<?php
/**
 * AWS Serverless Image Handler transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2022 André Elvan
 */

namespace spacecatninja\awsserverlesstransformer\transformers;

use Craft;
use craft\base\Component;
use craft\awss3\Fs;
use craft\elements\Asset;

use craft\helpers\App;
use spacecatninja\awsserverlesstransformer\AwsServerlessTransformer;
use spacecatninja\awsserverlesstransformer\helpers\AwsServerlessHelpers;
use spacecatninja\awsserverlesstransformer\models\AwsServerlessTransformedImageModel;
use spacecatninja\awsserverlesstransformer\models\Settings;
use spacecatninja\imagerx\services\ImagerService;
use spacecatninja\imagerx\transformers\TransformerInterface;
use spacecatninja\imagerx\exceptions\ImagerException;

class AwsServerless extends Component implements TransformerInterface
{

    public static array $strategyKeyTranslate = [
        'fit' => 'inside',
        'crop' => 'cover',
        'stretch' => 'fill',
        'letterbox' => 'contain'
    ];

    public static array $formatTranslate = [
        'jpg' => 'jpeg',
        'JPG' => 'jpeg',
        'JPEG' => 'jpeg',
        'PNG' => 'png'
    ];
    
    /**
     * @param Asset|string $image
     * @param array $transforms
     *
     * @return array|null
     *
     * @throws ImagerException
     */
    public function transform(Asset|string $image, array $transforms): ?array
    {
        $transformedImages = [];

        foreach ($transforms as $transform) {
            $transformedImages[] = $this->getTransformedImage($image, $transform);
        }

        return $transformedImages;
    }

    /**
     * @param Asset $image
     * @param array $transform
     *
     * @return AwsServerlessTransformedImageModel
     * @throws ImagerException
     */
    private function getTransformedImage(Asset $image, array $transform): AwsServerlessTransformedImageModel
    {
        /** @var Settings $settings */
        $settings = AwsServerlessTransformer::getInstance()?->getSettings();
        $config = ImagerService::getConfig();
        $transformerParams = $transform['transformerParams'] ?? [];
        $bgColor = $config->getSetting('bgColor', $transform);
        
        $requestParams = [
            'bucket' => App::parseEnv($settings->defaultBucket)
        ];

        // Get bucket from volume if possible
        try {
            $fs = $image->getVolume()->getFs();

            if ($fs instanceof Fs) {
                $bucket = App::parseEnv($fs->bucket);
                $requestParams['bucket'] = $bucket;
            }
        } catch (\Throwable $e) {
            Craft::error('Could not get filesystem from image: ' . $e->getMessage(), __METHOD__);
        }

        $requestParams['key'] = AwsServerlessHelpers::getImageKey($image);

        $edits = [
            'resize' => $transformerParams['resize'] ?? []
        ];
        
        unset($transformerParams['resize']);

        // Should we keep meta data?
        if (!$config->getSetting('removeMetadata', $transform) || $config->getSetting('preserveColorProfiles', $transform)) {
            $edits['withMetadata'] = true;
        }
        
        // Set resize 
        if (isset($transform['width'])) {
            if (isset($transform['pad'])) {
                $edits['resize']['width'] = $transform['width'] - $transform['pad'][1] - $transform['pad'][3];
            } else {
                $edits['resize']['width'] = $transform['width'];
            }
        }
        
        if (isset($transform['height'])) {
            if (isset($transform['pad'])) {
                $edits['resize']['height'] = $transform['height'] - $transform['pad'][0] - $transform['pad'][2];
            } else {
                $edits['resize']['height'] = $transform['height'];
            }
        }
        
        // Handle position
        if (isset($transform['position']) && !isset($edits['resize']['position'])) {
            $edits['resize']['position'] = AwsServerlessHelpers::getPosition($transform['position']);
        }
        
        // Convert mode to fit
        if (!isset($edits['resize']['fit'])) {
            $edits['resize']['fit'] = self::$strategyKeyTranslate[$transform['mode'] ?? 'crop'] ?? 'cover';
        }

        // If mode is letterbox, add letterbox color definition
        if ($edits['resize']['fit'] === 'contain' && !isset($edits['resize']['background'])) {
            $letterboxDef = $config->getSetting('letterbox', $transform);
            $edits['resize']['background'] = AwsServerlessHelpers::getLetterboxColor($letterboxDef);
        }
       
        // If padding is set, add it.
        if (isset($transform['pad']) && !isset($edits['extend'])) {
            $edits['extend'] = [
                'top' => $transform['pad'][0],
                'bottom' => $transform['pad'][2],
                'left' => $transform['pad'][1],
                'right' => $transform['pad'][3]
            ];
            
            if (!empty($bgColor) && $bgColor !== 'transparent') {
                $edits['extend']['background'] = AwsServerlessHelpers::parseColor($bgColor);
            }
        }
        
        // Set format
        $format = $transformerParams['toFormat'] ?? $transform['format'] ?? $image->getExtension();
        
        if ($format === 'gif') { // If format is `gif`, we have to deal with it...
            if (!empty($settings->autoConvertGif)) {
                if (is_string($settings->autoConvertGif)) {
                    $format = $settings->autoConvertGif;
                } else {
                    $format = 'png';
                }
            } else {
                $message = 'The AWS Serverless Image Handler does not support converting images to GIF. Please use `autoConvertGif` or add conditionals to avoid passing in GIFs.';
                Craft::error($message, __METHOD__);
                throw new ImagerException($message);
            }
        }

        $format = self::$formatTranslate[$format] ?? $format;
        $edits['toFormat'] = $format;
        $edits[$format] = $transformerParams[$format] ?? AwsServerlessHelpers::getFormatOptions($format, $config);
        
        unset($transformerParams['toFormat'], $transformerParams['jpeg'], $transformerParams['png'], $transformerParams['webp']);

        // Add bg color
        if (!empty($bgColor) && $bgColor !== 'transparent') {
            if (!isset($edits['flatten'])) {
                $edits['flatten'] = [];
            }
            $edits['flatten']['background'] = AwsServerlessHelpers::parseColor($bgColor);
        }

        // Effects
        if (isset($transform['effects'])) {
            $effects = $transform['effects'];
            $edits = array_merge($edits, AwsServerlessHelpers::convertEffects($effects));
        }
        
        // Merge the rest of the submitted `transformParams` into edits
        $edits = array_merge($edits, $transformerParams);

        $requestParams['edits'] = $edits;

        // Encode the $config and create the $url
        try {
            $encodedRequestParams = json_encode($requestParams, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        } catch (\Throwable $e) {
            Craft::error($e->getMessage());
            throw new ImagerException($e->getMessage());
        }

        $path = '/' . base64_encode($encodedRequestParams);
        $url = rtrim($settings->distributionUrl, '/') . $path;

        if (! empty($settings->signatureKey)) {
            // Prepare the signature if the settings model has a key we should use
            $signature = hash_hmac('sha256', $path, $settings->signatureKey);
            // Append the signature to the URL
            $url .= '?signature=' . $signature;
        }
        
        return new AwsServerlessTransformedImageModel($url, $image, $requestParams);
    }
}
