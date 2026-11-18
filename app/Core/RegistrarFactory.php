<?php
declare(strict_types=1);

namespace HostPilot\Core;

use RuntimeException;

final class RegistrarFactory
{
    public static function supportedProviders():array
    {
        return ['resellerclub'];
    }

    public static function create(string $provider,string $userId,string $apiKey,string $environment='sandbox'):DomainRegistrarInterface
    {
        $provider=strtolower(trim($provider));
        if($provider==='resellerclub')return new ResellerClubClient($userId,$apiKey,$environment);
        throw new RuntimeException('Desteklenmeyen domain registrar sağlayıcısı: '.$provider);
    }
}
