<?php

// Standalone regression tests; never clear the installation's actual cache.
namespace Piwik {
    class Piwik
    {
        public static $superuser = false;

        public static function hasUserSuperUserAccess()
        {
            return self::$superuser;
        }

        public static function checkUserHasSuperUserAccess()
        {
            if (!self::$superuser) {
                throw new \Exception('Access denied');
            }
        }

        public static function translate($key, $parameters = [])
        {
            return $key;
        }
    }

    class Filesystem
    {
        public static $calls = 0;
        public static $fail = false;

        public static function deleteAllCacheOnUpdate()
        {
            ++self::$calls;
            if (self::$fail) {
                throw new \RuntimeException('Cache failure');
            }
        }
    }

    class Common
    {
        public static function generateUniqId()
        {
            return bin2hex(random_bytes(16));
        }
    }

    class SettingsPiwik
    {
        public static function getSalt()
        {
            return 'test-salt';
        }
    }

    class Config
    {
        public $General = ['force_ssl' => 0];

        public static function getInstance()
        {
            return new self();
        }
    }

    class Url
    {
        public static function isSecureConnectionAssumedByPiwikButNotForcedYet()
        {
            return false;
        }

        public static function getReferrer()
        {
            return $_SERVER['HTTP_REFERER'] ?? '';
        }

        public static function getCurrentHost($default = null)
        {
            return 'matomo.example';
        }

        public static function isLocalUrl($url)
        {
            return parse_url($url, PHP_URL_HOST) === 'matomo.example';
        }

        public static function getExternalLinkTag($url)
        {
            return '<a>';
        }
    }

    class Development
    {
        public static function isEnabled()
        {
            return false;
        }
    }
}

namespace Piwik\Session {
    class SessionNamespace
    {
        private static $values = [];
        private $id;

        public function __construct($id)
        {
            $this->id = $id;
        }

        public function __get($name)
        {
            return self::$values[$this->id][$name] ?? null;
        }

        public function __set($name, $value)
        {
            self::$values[$this->id][$name] = $value;
        }

        public function setExpirationSeconds($ttl, $name)
        {
            // Expiry is simulated explicitly by removing the session value below.
        }

        public function unsetAll()
        {
            unset(self::$values[$this->id]);
        }
    }
}

namespace Piwik\Plugin {
    class ControllerAdmin
    {
        protected function renderTemplate($template, array $variables = [])
        {
            return $variables;
        }
    }
}

namespace Piwik\Menu {
    class MenuAdmin
    {
        public $items = [];

        public function addSystemItem($label, $url, $order)
        {
            $this->items[] = [$label, $url, $order];
        }
    }
}

namespace {
    use Piwik\Filesystem;
    use Piwik\Nonce;
    use Piwik\Piwik;
    use Piwik\Plugins\NeorClearCache\Controller;
    use Piwik\Plugins\NeorClearCache\Menu;

    $root = dirname(__DIR__, 3);
    require $root . '/vendor/autoload.php';
    require $root . '/core/Nonce.php';
    require $root . '/core/Plugin/Menu.php';
    require dirname(__DIR__) . '/Controller.php';
    require dirname(__DIR__) . '/Menu.php';

    function check($condition, $label)
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
        echo "PASS $label\n";
    }

    function rejectsWithoutClearing(callable $call, $label)
    {
        $before = Filesystem::$calls;
        try {
            $call();
        } catch (\Exception $exception) {
            check(Filesystem::$calls === $before, $label);
            return;
        }
        throw new \RuntimeException('Did not reject: ' . $label);
    }

    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    $_GET = $_POST = [];
    $controller = new Controller();
    foreach (['anonymous', 'viewer', 'site administrator'] as $role) {
        foreach (['GET', 'POST'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            rejectsWithoutClearing(fn() => $controller->index(), "$role denied on $method");
        }
    }
    $menu = new \Piwik\Menu\MenuAdmin();
    (new Menu())->configureAdminMenu($menu);
    check($menu->items === [], 'menu hidden without superuser access');

    Piwik::$superuser = true;
    (new Menu())->configureAdminMenu($menu);
    check(count($menu->items) === 1 && $menu->items[0][1] === [
        'action' => 'index', 'module' => 'NeorClearCache',
    ], 'superuser menu points to NeorClearCache');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $view = $controller->index();
    $token = $view['neorClearCacheNonce'];
    $_GET['nonce'] = $token;
    $_POST['nonce'] = $token;
    check(!$controller->index()['neorClearCacheCleared'] && Filesystem::$calls === 0, 'GET never clears');

    foreach (['HEAD', 'PUT', 'DELETE'] as $method) {
        $_SERVER['REQUEST_METHOD'] = $method;
        rejectsWithoutClearing(fn() => $controller->index(), "$method rejected");
    }

    $_SERVER['REQUEST_METHOD'] = 'POST';
    foreach ([null, '', ['bad'], str_repeat('x', 8192), str_repeat('0', 32)] as $nonce) {
        $_POST = $nonce === null ? [] : ['nonce' => $nonce];
        rejectsWithoutClearing(fn() => $controller->index(), 'missing or invalid POST nonce rejected');
    }
    // A valid token supplied only in the URL must not authorize the POST.
    $_GET['nonce'] = Nonce::getNonce('NeorClearCache.clear');
    $_POST = [];
    rejectsWithoutClearing(fn() => $controller->index(), 'URL-only nonce rejected');

    foreach (['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/'] as $header => $value) {
        $_POST = ['nonce' => Nonce::getNonce('NeorClearCache.clear')];
        $_SERVER[$header] = $value;
        rejectsWithoutClearing(fn() => $controller->index(), "$header rejected by real Matomo Nonce");
        unset($_SERVER[$header]);
    }

    $expired = Nonce::getNonce('NeorClearCache.clear');
    Nonce::discardNonce('NeorClearCache.clear');
    $_POST = ['nonce' => $expired];
    rejectsWithoutClearing(fn() => $controller->index(), 'expired session nonce rejected');

    $_SERVER['HTTP_ORIGIN'] = 'https://matomo.example';
    $_SERVER['HTTP_REFERER'] = 'https://matomo.example/index.php';
    $token = Nonce::getNonce('NeorClearCache.clear');
    $_POST = ['nonce' => $token];
    $view = $controller->index();
    check($view['neorClearCacheCleared'] && Filesystem::$calls === 1, 'authorized POST clears exactly once');
    check($view['neorClearCacheNonce'] !== $token, 'new nonce after successful clear');
    rejectsWithoutClearing(fn() => $controller->index(), 'replayed POST rejected');

    Filesystem::$fail = true;
    $_POST = ['nonce' => Nonce::getNonce('NeorClearCache.clear')];
    try {
        $controller->index();
        throw new \LogicException('Cache failure was swallowed');
    } catch (\RuntimeException $exception) {
        check($exception->getMessage() === 'Cache failure', 'clear failure cannot report success');
    }

    // Render the actual plugin template using bundled Twig, with a minimal admin shell.
    $loader = new \Twig\Loader\ChainLoader([
        new \Twig\Loader\ArrayLoader(['admin.twig' => '{% block content %}{% endblock %}']),
        new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates'),
    ]);
    $twig = new \Twig\Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]);
    $translations = json_decode(file_get_contents(dirname(__DIR__) . '/lang/ru.json'), true, 512, JSON_THROW_ON_ERROR);
    $twig->addFilter(new \Twig\TwigFilter('translate', function ($key) use ($translations) {
        return $translations['NeorClearCache'][substr($key, strlen('NeorClearCache_'))];
    }));
    foreach ([false, true] as $cleared) {
        $html = $twig->render('index.twig', ['neorClearCacheCleared' => $cleared, 'neorClearCacheNonce' => '"><script>bad</script>']);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);
        check($xpath->query('//form[@method="post" and @action="index.php?module=NeorClearCache&action=index"]')->length === 1, 'POST form targets correct controller');
        check($xpath->query('//button[@type="submit"]')->item(0)->textContent === 'Очистить кеш', 'Russian button label');
        check($xpath->query('//input[@name="nonce"]')->length === 1 && $xpath->query('//script')->length === 0, 'nonce present and HTML escaped');
        check($xpath->query('//*[@role="status"]')->length === (int) $cleared, 'success shown only after clear');
    }
    foreach (['plugin.json', 'lang/en.json', 'lang/ru.json', 'lang/fr.json', 'lang/es.json', 'lang/pt.json', 'lang/pt-br.json', 'lang/de.json', 'lang/it.json'] as $file) {
        json_decode(file_get_contents(dirname(__DIR__) . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }
    echo "All NeorClearCache checks passed.\n";
}
