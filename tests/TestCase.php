<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Forget the current browser: guards, session store and cookies, so the
     * next request arrives like one from a different device.
     */
    protected function freshBrowser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }
}
