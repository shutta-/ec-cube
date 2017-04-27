<?php


namespace Eccube\ServiceProvider;

use Eccube\Common\Constant;
use Eccube\Plugin\ConfigManager as PluginConfigManager;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;


class EccubePluginServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function register(Container $app)
    {
        // EventDispatcher
        $app['eccube.event.dispatcher'] = function () {
            return new EventDispatcher();
        };

        // プラグインディレクトリを探索.
        $pluginConfigs = PluginConfigManager::getPluginConfigAll($app['debug']);

        foreach ($pluginConfigs as $code => $pluginConfig) {
            $config = $pluginConfig['config'];

            if (isset($config['const'])) {
                $app->extend('config', function ($eccubeConfig) use ($config) {
                    $eccubeConfig[$config['code']] = array(
                        'const' => $config['const'],
                    );
                    return $eccubeConfig;
                });
            }

            // Type: ServiceProvider
            if (isset($config['service'])) {
                foreach ($config['service'] as $service) {
                    $class = '\\Plugin\\'.$config['code'].'\\ServiceProvider\\'.$service;
                    if (!class_exists($class)) {
                        $app['monolog']->warning("Service provider class for plugin {$code} not exists.", array(
                            'class' => $class,
                        ));
                        continue;
                    }
                    $app->register(new $class($this));
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function boot(Application $app)
    {
        $this->initPluginEventDispatcher($app);
        $this->loadPlugin($app);
    }

    public function initPluginEventDispatcher(Application $app)
    {
        // hook point
        $app->on(KernelEvents::REQUEST, function (GetResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $hookpoint = 'eccube.event.app.before';
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        }, Application::EARLY_EVENT);

        $app->on(KernelEvents::REQUEST, function (GetResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $route = $event->getRequest()->attributes->get('_route');
            $hookpoint = "eccube.event.controller.$route.before";
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        });

        $app->on(KernelEvents::RESPONSE, function (FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $route = $event->getRequest()->attributes->get('_route');
            $hookpoint = "eccube.event.controller.$route.after";
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        });

        $app->on(KernelEvents::RESPONSE, function (FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $hookpoint = 'eccube.event.app.after';
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        }, Application::LATE_EVENT);

        $app->on(KernelEvents::TERMINATE, function (PostResponseEvent $event) use ($app) {
            $route = $event->getRequest()->attributes->get('_route');
            $hookpoint = "eccube.event.controller.$route.finish";
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        });

        $app->on(\Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function (\Symfony\Component\HttpKernel\Event\FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $route = $event->getRequest()->attributes->get('_route');
            $app['eccube.event.dispatcher']->dispatch('eccube.event.render.'.$route.'.before', $event);
        });

        // Request Event
        $app->on(\Symfony\Component\HttpKernel\KernelEvents::REQUEST, function (\Symfony\Component\HttpKernel\Event\GetResponseEvent $event) use ($app) {

            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::REQUEST '.$route);

            // 全体
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.request', $event);

            if (strpos($route, 'admin') === 0) {
                // 管理画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.request', $event);
            } else {
                // フロント画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.request', $event);
            }

            // ルーティング単位
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.request", $event);

        }, 30); // Routing(32)が解決し, 認証判定(8)が実行される前のタイミング.

        // Controller Event
        $app->on(\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER, function (\Symfony\Component\HttpKernel\Event\FilterControllerEvent $event) use ($app) {

            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::CONTROLLER '.$route);

            // 全体
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.controller', $event);

            if (strpos($route, 'admin') === 0) {
                // 管理画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.controller', $event);
            } else {
                // フロント画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.controller', $event);
            }
            // ルーティング単位
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.controller", $event);
        });

        // Response Event
        $app->on(\Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function (\Symfony\Component\HttpKernel\Event\FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::RESPONSE '.$route);

            // ルーティング単位
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.response", $event);

            if (strpos($route, 'admin') === 0) {
                // 管理画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.response', $event);
            } else {
                // フロント画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.response', $event);
            }

            // 全体
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.response', $event);
        });

        // Exception Event
        $app->on(\Symfony\Component\HttpKernel\KernelEvents::EXCEPTION, function (\Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event) use ($app) {

            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::EXCEPTION '.$route);

            // ルーティング単位
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.exception", $event);

            if (strpos($route, 'admin') === 0) {
                // 管理画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.exception', $event);
            } else {
                // フロント画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.exception', $event);
            }

            // 全体
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.exception', $event);
        });

        // Terminate Event
        $app->on(\Symfony\Component\HttpKernel\KernelEvents::TERMINATE, function (\Symfony\Component\HttpKernel\Event\PostResponseEvent $event) use ($app) {

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::TERMINATE '.$route);

            // ルーティング単位
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.terminate", $event);

            if (strpos($route, 'admin') === 0) {
                // 管理画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.terminate', $event);
            } else {
                // フロント画面
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.terminate', $event);
            }

            // 全体
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.terminate', $event);
        });
    }

    public function loadPlugin(Application $app)
    {
        // ハンドラ優先順位をdbから持ってきてハッシュテーブルを作成
        $priorities = array();
        $pluginConfigs = PluginConfigManager::getPluginConfigAll($app['debug']);

        $handlers = $app['orm.em']
            ->getRepository('Eccube\Entity\PluginEventHandler')
            ->getHandlers();

        foreach ($handlers as $handler) {
            if ($handler->getPlugin()->getEnable() && !$handler->getPlugin()->getDelFlg()) {

                $priority = $handler->getPriority();
            } else {
                // Pluginがdisable、削除済みの場合、EventHandlerのPriorityを全て0とみなす
                $priority = \Eccube\Entity\PluginEventHandler::EVENT_PRIORITY_DISABLED;
            }
            $priorities[$handler->getPlugin()->getClassName()][$handler->getEvent()][$handler->getHandler()] = $priority;
        }

        // プラグインをロードする.
        // config.yml/event.ymlの定義に沿ってインスタンスの生成を行い, イベント設定を行う.
        foreach ($pluginConfigs as $code => $pluginConfig) {
            // 正しい形式の pluginConfig のみ読み込む
            $path = PluginConfigManager::getPluginRealDir().'/'.$code;
            try {
                $app['eccube.service.plugin']->checkPluginArchiveContent($path, $pluginConfig['config']);
            } catch (\Eccube\Exception\PluginException $e) {
                $app['monolog']->warning("Configuration file config.yml for plugin {$code} not found or is invalid. Skipping loading.", array(
                    'path' => $path,
                    'original-message' => $e->getMessage()
                ));
                continue;
            }
            $config = $pluginConfig['config'];

            $plugin = $app['orm.em']
                ->getRepository('Eccube\Entity\Plugin')
                ->findOneBy(array('code' => $config['code']));

            // const
            if ($plugin && $plugin->getEnable() == Constant::DISABLED) {
                // プラグインが無効化されていれば読み込まない
                continue;
            }

            // Type: Event
            if (isset($config['event'])) {
                $class = '\\Plugin\\'.$config['code'].'\\'.$config['event'];
                $eventExists = true;

                if (!class_exists($class)) {
                    $app['monolog']->warning("Event class for plugin {$code} not exists.", array(
                        'class' => $class,
                    ));
                    $eventExists = false;
                }

                if ($eventExists && isset($config['event'])) {

                    $subscriber = new $class($app);

                    foreach ($pluginConfig['event'] as $event => $handlers) {
                        foreach ($handlers as $handler) {
                            if (!isset($priorities[$config['event']][$event][$handler[0]])) { // ハンドラテーブルに登録されていない（ソースにしか記述されていない)ハンドラは一番後ろにする
                                $priority = \Eccube\Entity\PluginEventHandler::EVENT_PRIORITY_LATEST;
                            } else {
                                $priority = $priorities[$config['event']][$event][$handler[0]];
                            }
                            // 優先度が0のプラグインは登録しない
                            if (\Eccube\Entity\PluginEventHandler::EVENT_PRIORITY_DISABLED != $priority) {
                                $app['eccube.event.dispatcher']->addListener($event, array($subscriber, $handler[0]), $priority);
                            }
                        }
                    }
                }
            }
        }
    }
}