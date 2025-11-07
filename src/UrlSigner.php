<?php

namespace craft\cloud;

use Craft;
use craft\helpers\UrlHelper;

class UrlSigner
{
    public function __construct(
        private readonly string $signingKey,
        private readonly string $signatureParameter = 's',
    ) {
    }

    public function sign(string $url): string
    {
        return UrlHelper::urlWithParams($url, [
            $this->signatureParameter => hash_hmac('sha256', $url, $this->signingKey),
        ]);
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

        $verified = hash_equals(
            hash_hmac('sha256', $urlWithoutSignature, $this->signingKey),
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
