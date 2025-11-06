<?php

namespace craft\cloud;

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
            return false;
        }

        parse_str($query, $params);

        $providedSignature = $params[$this->signatureParameter] ?? null;

        if (!$providedSignature) {
            return false;
        }

        $urlWithoutSignature = UrlHelper::removeParam($url, $this->signatureParameter);

        return hash_equals(
            hash_hmac('sha256', $urlWithoutSignature, $this->signingKey),
            $providedSignature,
        );
    }
}
