<?php

namespace craft\cloud;

use Craft;
use League\Uri\Components\Query;
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
        $normalizedUrl = $this->prepareUrlForSigning($url);

        Craft::info([
            'message' => 'Signing URL',
            'url' => $normalizedUrl,
        ], __METHOD__);

        $signature = hash_hmac('sha256', $normalizedUrl, $this->signingKey);

        return Modifier::from($normalizedUrl)->appendQueryParameters([
            $this->signatureParameter => $signature,
        ]);
    }

    private function prepareUrlForSigning(string $url): string
    {
        return Modifier::from($url)
            ->removeQueryParameters($this->signatureParameter)
            ->sortQuery();
    }

    public function verify(string $url): bool
    {
        $providedSignature = Query::fromUri($url)->get($this->signatureParameter);

        if (!$providedSignature) {
            Craft::info('Missing signature', __METHOD__);

            return false;
        }

        $normalizedUrl = $this->prepareUrlForSigning($url);

        $verified = hash_equals(
            hash_hmac('sha256', $normalizedUrl, $this->signingKey),
            $providedSignature,
        );

        if (!$verified) {
            Craft::info([
                'message' => 'Invalid signature',
                'signatureParameter' => $this->signatureParameter,
                'providedSignature' => $providedSignature,
                'url' => $normalizedUrl,
            ], __METHOD__);
        }

        return $verified;
    }
}
