<?php

namespace Piwik\Plugins\NeorClearCache;

use Piwik\Filesystem;
use Piwik\Nonce;
use Piwik\Piwik;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    private const CLEAR_NONCE = 'NeorClearCache.clear';

    public function index()
    {
        Piwik::checkUserHasSuperUserAccess();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $cleared = false;

        if ($method === 'POST') {
            // Read only the POST body: a nonce in the URL must not authorize a write.
            $nonce = $_POST['nonce'] ?? '';
            if (!is_string($nonce) || strlen($nonce) !== 32) {
                throw new \Exception(Piwik::translate('General_ExceptionSecurityCheckFailed'));
            }

            Nonce::checkNonce(self::CLEAR_NONCE, $nonce);
            Filesystem::deleteAllCacheOnUpdate();
            $cleared = true;
        } elseif ($method !== 'GET') {
            throw new \Exception(Piwik::translate('General_ExceptionSecurityCheckFailed'));
        }

        return $this->renderTemplate('index', [
            'neorClearCacheNonce' => Nonce::getNonce(self::CLEAR_NONCE),
            'neorClearCacheCleared' => $cleared,
        ]);
    }
}
