<?php

namespace Piwik\Plugins\NeorClearCache;

use Piwik\Menu\MenuAdmin;
use Piwik\Piwik;

class Menu extends \Piwik\Plugin\Menu
{
    public function configureAdminMenu(MenuAdmin $menu)
    {
        if (Piwik::hasUserSuperUserAccess()) {
            $menu->addSystemItem('NeorClearCache_ClearCache', $this->urlForAction('index'), 40);
        }
    }
}
