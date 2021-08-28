<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitOAuth2
{
    public static $prefixLengthsPsr4 = array (
        'O' => 
        array (
            'OCA\\OAuth2\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'OCA\\OAuth2\\' => 
        array (
            0 => __DIR__ . '/..' . '/../lib',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'OCA\\OAuth2\\Controller\\LoginRedirectorController' => __DIR__ . '/..' . '/../lib/Controller/LoginRedirectorController.php',
        'OCA\\OAuth2\\Controller\\OauthApiController' => __DIR__ . '/..' . '/../lib/Controller/OauthApiController.php',
        'OCA\\OAuth2\\Controller\\SettingsController' => __DIR__ . '/..' . '/../lib/Controller/SettingsController.php',
        'OCA\\OAuth2\\Db\\AccessToken' => __DIR__ . '/..' . '/../lib/Db/AccessToken.php',
        'OCA\\OAuth2\\Db\\AccessTokenMapper' => __DIR__ . '/..' . '/../lib/Db/AccessTokenMapper.php',
        'OCA\\OAuth2\\Db\\Client' => __DIR__ . '/..' . '/../lib/Db/Client.php',
        'OCA\\OAuth2\\Db\\ClientMapper' => __DIR__ . '/..' . '/../lib/Db/ClientMapper.php',
        'OCA\\OAuth2\\Exceptions\\AccessTokenNotFoundException' => __DIR__ . '/..' . '/../lib/Exceptions/AccessTokenNotFoundException.php',
        'OCA\\OAuth2\\Exceptions\\ClientNotFoundException' => __DIR__ . '/..' . '/../lib/Exceptions/ClientNotFoundException.php',
        'OCA\\OAuth2\\Migration\\SetTokenExpiration' => __DIR__ . '/..' . '/../lib/Migration/SetTokenExpiration.php',
        'OCA\\OAuth2\\Migration\\Version010401Date20181207190718' => __DIR__ . '/..' . '/../lib/Migration/Version010401Date20181207190718.php',
        'OCA\\OAuth2\\Migration\\Version010402Date20190107124745' => __DIR__ . '/..' . '/../lib/Migration/Version010402Date20190107124745.php',
        'OCA\\OAuth2\\Settings\\Admin' => __DIR__ . '/..' . '/../lib/Settings/Admin.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitOAuth2::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitOAuth2::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitOAuth2::$classMap;

        }, null, ClassLoader::class);
    }
}