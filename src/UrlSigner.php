<?php

namespace craft\cloud;

use Craft;
use craft\helpers\UrlHelper;
use League\Uri\Modifier;

class UrlSigner
{
    public function __construct(
        private readonly string $signingKey,
        private readonly string $signatureParameter = 's',
    ) {
    }

    public function sign(string $url): string
    {
        $normalizedUrl = $this->normalizeUrl($url);

        Craft::info([
            'message' => 'Signing URL',
            'url' => $url,
            'normalizedUrl' => $normalizedUrl,
        ], __METHOD__);

        return UrlHelper::urlWithParams($url, [
            $this->signatureParameter => hash_hmac(
                'sha256',
                $normalizedUrl,
                $this->signingKey,
            ),
        ]);
    }
    private function normalizeUrl(string $url): string
    {
        return Modifier::from($url)->sortQuery();
    }

    public function verify(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (!$query) {
            Craft::info('Missing signature', __METHOD__);

            return false;
        }

        parse_str($query, $params);

        $providedSignature = $params[$this->signatureParameter] ?? null;

        if (!$providedSignature) {
            Craft::info('Missing signature', __METHOD__);

            return false;
        }

        $urlWithoutSignature = UrlHelper::removeParam($url, $this->signatureParameter);
        $normalizedUrl = $this->normalizeUrl($urlWithoutSignature);

        $verified = hash_equals(
            hash_hmac('sha256', $normalizedUrl, $this->signingKey),
            $providedSignature,
        );

        if (!$verified) {
            Craft::info([
                'message' => 'Invalid signature',
                'signatureParameter' => $this->signatureParameter,
                'providedSignature' => $providedSignature,
                'urlWithoutSignature' => $urlWithoutSignature,
                'url' => $url,
            ], __METHOD__);
        }

        return $verified;
    }
}
