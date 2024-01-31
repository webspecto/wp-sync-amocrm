<?php

/**
 * @author Iulian Ceapa <dev@webspecto.com>
 * @copyright © 2023-2024 WebSpecto.
 */

use AmoCRM\Client\AmoCRMApiClient;
use Symfony\Component\Dotenv\Dotenv;

if (file_exists($file_auth = __DIR__ . '/secret/auth.env')) {
    include __DIR__ . '/vendor/autoload.php';

    define('FILE_AUTH_TOKEN', __DIR__ . '/secret/auth_token.json');

    $dotenv = new Dotenv(false);
    $dotenv->load($file_auth);

    $api_client = new AmoCRMApiClient($_ENV['CLIENT_ID'], $_ENV['CLIENT_SECRET'], $_ENV['CLIENT_REDIRECT_URI']);
    $api_client->getOAuthClient()->setBaseDomain($_ENV['CLIENT_BASE_DOMAIN']);

    function saveToken($accessToken)
    {
        if (
            isset($accessToken['accessToken'])
            && isset($accessToken['refreshToken'])
            && isset($accessToken['expires'])
            && isset($accessToken['baseDomain'])
        ) {
            $data = [
                'accessToken' => $accessToken['accessToken'],
                'refreshToken' => $accessToken['refreshToken'],
                'expires' => $accessToken['expires'],
                'baseDomain' => $accessToken['baseDomain'],
            ];

            file_put_contents(FILE_AUTH_TOKEN, json_encode($data));
        } else {
            throw new \Exception('Invalid access token ' . var_export($accessToken, true));
        }
    }

    session_start();

    if (isset($_GET['referer'])) {
        $api_client->setAccountBaseDomain($_GET['referer']);
    }

    if (!isset($_GET['code'])) {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2state'] = $state;
        $authorizationUrl = $api_client->getOAuthClient()->getAuthorizeUrl([
            'state' => $state,
            'mode' => 'post_message',
        ]);
        exit(header('Location: ' . $authorizationUrl));
    } elseif (($_GET['state'] !== $_SESSION['oauth2state'])) {
        unset($_SESSION['oauth2state']);
        throw new \Exception('Invalid state');
    }

    $access_token = $api_client->getOAuthClient()->getAccessTokenByCode($_GET['code']);
    if (!$access_token->hasExpired()) {
        saveToken([
            'accessToken' => $access_token->getToken(),
            'refreshToken' => $access_token->getRefreshToken(),
            'expires' => $access_token->getExpires(),
            'baseDomain' => $api_client->getAccountBaseDomain(),
        ]);
    }

    $owner_details = $api_client->getOAuthClient()->getResourceOwner($access_token);
}

header('Location: https://' . $_SERVER['HTTP_HOST'] . '/wp-admin/admin.php?page=wpsyncamo');
