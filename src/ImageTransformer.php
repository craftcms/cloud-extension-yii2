<?php

namespace craft\cloud;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\ImageEditorTransformerInterface;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\ImageTransform;
use Illuminate\Support\Collection;

/**
 * TODO: ImageEditorTransformerInterface
 */
class ImageTransformer extends Component implements ImageTransformerInterface
{
    public const SIGNING_PARAM = 's';
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'gif', 'png', 'avif'];

    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        $assetUrl = $asset->getUrl();

        if (!$assetUrl) {
            return '';
        }

        $transformParams = $this->buildTransformParams($imageTransform);
        $path = parse_url($assetUrl, PHP_URL_PATH);
        $params = $transformParams + [
            self::SIGNING_PARAM => $this->sign($path, $transformParams),
        ];

        return UrlHelper::urlWithParams($assetUrl, $params);
    }

    public function invalidateAssetTransforms(Asset $asset): void
    {
    }

    public function buildTransformParams(ImageTransform $imageTransform): array
    {

//        'anim',
//        'background',
//        'blur',
//        'border',
//        'brightness',
//        'compression',
//        'contrast',
//        'dpr',
//        'fit',
//        'format',
//        'gamma',
//        'gravity',
//        'height',
//        'metadata',
//        'onerror',
//        'quality',
//        'rotate',
//        'sharpen',
//        'trim',
//        'width',

        return Collection::make([
            'width' => $imageTransform->width,
            'height' => $imageTransform->height,
            'quality' => $imageTransform->quality,
            'format' => $imageTransform->format,
        ])->whereNotNull()->all();
    }

    public function sign(string $path, $params): string
    {
        $paramString = http_build_query($params);
        $data = "$path#?$paramString";

        Craft::info("Signing transform: “{$data}”");

        return base64_encode(hash_hmac(
            'sha256',
            $data,
            Module::getInstance()->getConfig()->cdnSigningKey,
        ));
    }
}
