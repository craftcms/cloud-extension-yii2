<?php

namespace craft\cloud;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\helpers\Assets;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\ImageTransform;
use Illuminate\Support\Collection;
use yii\base\NotSupportedException;

/**
 * TODO: ImageEditorTransformerInterface
 */
class ImageTransformer extends Component implements ImageTransformerInterface
{
    public const SIGNING_PARAM = 's';
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'gif', 'png', 'avif'];
    protected Asset $asset;

    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        $this->asset = $asset;
        $assetUrl = Html::encodeSpaces(Assets::generateUrl($this->asset));
        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/gif' && !Craft::$app->getConfig()->getGeneral()->transformGifs) {
            throw new NotSupportedException('GIF files shouldn’t be transformed.');
        }

        if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
            throw new NotSupportedException('SVG files shouldn’t be transformed.');
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
        return Collection::make([
            'width' => $imageTransform->width,
            'height' => $imageTransform->height,
            'quality' => $imageTransform->quality,
            'format' => $this->getFormatValue($imageTransform),
            'fit' => $this->getFitValue($imageTransform),
            'background' => $this->getBackgroundValue($imageTransform),
            'gravity' => $this->getGravityValue($imageTransform),
        ])->whereNotNull()->all();
    }

    protected function getGravityValue(ImageTransform $imageTransform): ?array
    {
        if ($this->asset->getHasFocalPoint()) {
            return $this->asset->getFocalPoint();
        }

        if ($imageTransform->position === 'center-center') {
            return null;
        }

        // TODO: maybe just do this in Craft
        $parts = explode('-', $imageTransform->position);
        $yPosition = $parts[0] ?? null;
        $xPosition = $parts[1] ?? null;

        try {
            $x = match ($xPosition) {
                'top' => 0,
                'center' => 0.5,
                'bottom' => 1,
            };
            $y = match ($yPosition) {
                'top' => 0,
                'center' => 0.5,
                'bottom' => 1,
            };
        } catch (\UnhandledMatchError $e) {
            throw new ImageTransformException('Invalid `position` value.');
        }

        return [$x, $y];
    }

    protected function getBackgroundValue(ImageTransform $imageTransform): ?string
    {
        return $imageTransform->mode === 'letterbox'
            ? $imageTransform->fill ?? '#FFFFFF'
            : null;
    }

    protected function getFitValue(ImageTransform $imageTransform): string
    {
        // @see https://developers.cloudflare.com/images/transform-images/transform-via-url/#fit
        // Cloudflare doesn't have an exact match to `stretch`.
        // `cover` is close, but will crop instead of stretching.
        return match ($imageTransform->mode) {
            'fit' => $imageTransform->upscale ? 'contain' : 'scale-down',
            'stretch' => 'cover',
            'letterbox' => 'pad',
            default => $imageTransform->upscale ? 'cover' : 'crop',
        };
    }

    protected function getFormatValue(ImageTransform $imageTransform): string
    {
        if ($imageTransform->format === 'jpg' && $imageTransform->interlace === 'none') {
            return 'baseline-jpeg';
        }

        return match ($imageTransform->format) {
            'jpg' => 'jpeg',
            default => $imageTransform->format ?? 'auto',
        };
    }

    public function sign(string $path, $params): string
    {
        $paramString = http_build_query($params);
        $data = "$path#?$paramString";

        Craft::info("Signing transform: `{$data}`");

        // https://developers.cloudflare.com/workers/examples/signing-requests
        $hash = hash_hmac(
            'sha256',
            $data,
            Module::getInstance()->getConfig()->signingKey,
            true,
        );

        return Helper::base64UrlEncode($hash);
    }
}
