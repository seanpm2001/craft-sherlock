<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\sherlock\services;

use Craft;
use craft\base\Component;
use craft\base\Plugin;
use craft\helpers\ConfigHelper;
use craft\helpers\UrlHelper;
use craft\models\Updates;
use DateTime;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use putyourlightson\sherlock\models\TestModel;
use putyourlightson\sherlock\Sherlock;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;

/**
 * Tests Service
 *
 * @property array $testNames
 */
class TestsService extends Component
{
    /**
     * @var Updates
     */
    private $_updates;

    /**
     * @var array|null
     */
    private $_siteUrlResponse;

    /**
     * @var string|null
     */
    private $_cpUrlResponse;

    /**
     * Get test names
     *
     * @return array
     */
    public function getTestNames(): array
    {
        $tests = [
            // Updates
            'criticalCraftUpdates',
            'criticalPluginUpdates',
            'craftUpdates',
            'pluginUpdates',

            // HTTPS
            'httpsControlPanel',
            'httpsFrontEnd',

            // System
            'craftFilePermissions',
            'craftFolderPermissions',
            'craftFoldersAboveWebRoot',
            'phpVersion',

            // Setup
            'adminUsername',
            'requireEmailVerification',
            'webAliasInSiteBaseUrl',
            'webAliasInVolumeBaseUrl',

            // Headers
            'contentSecurityPolicy',
            'cors',
            'expectCT',
            'referrerPolicy',
            'strictTransportSecurity',
            'xContentTypeOptions',
            'xFrameOptions',
            'xXssProtection',

            // General config settings
            'blowfishHashCost',
            'cooldownDuration',
            'cpTrigger',
            'defaultDirMode',
            'defaultFileMode',
            'defaultTokenDuration',
            'deferPublicRegistrationPassword',
            'devMode',
            'elevatedSessionDuration',
            'enableCsrfProtection',
            'invalidLoginWindowDuration',
            'maxInvalidLogins',
            'preventUserEnumeration',
            'rememberedUserSessionDuration',
            'requireMatchingUserAgentForSession',
            'requireUserAgentAndIpForSession',
            'sanitizeSvgUploads',
            'testToEmailAddress',
            'translationDebugOutput',
            'userSessionDuration',
            'useSecureCookies',
            'verificationCodeDuration',
        ];

        // Remove disabled tests
        $disabledTests = Sherlock::$plugin->settings->disabledTests;

        if (is_array($disabledTests)) {
            $tests = array_values(array_diff($tests, $disabledTests));
        }

        return $tests;
    }

    /**
     * Run test
     *
     * @param string $test
     * @return TestModel
     * @throws HttpException
     */
    public function runTest(string $test): TestModel
    {
        $this->_beforeRunTests();

        $testModel = new TestModel(Sherlock::$plugin->settings->{$test});
        $testModel->highSecurityLevel = Sherlock::$plugin->settings->highSecurityLevel;

        switch ($test) {
            case 'criticalCraftUpdates':
                if ($this->_updates->cms->getHasCritical()) {
                    $criticalCraftUpdates = [];

                    foreach ($this->_updates->cms->releases as $release) {
                        if ($release->critical) {
                            $criticalCraftUpdates[] = '
                                <a href="https://github.com/craftcms/cms/blob/master/CHANGELOG-v3.md#'.str_replace('.', '-', $release->version).'" target="_blank">'.$release->version.'</a> 
                                <span class="info">Version '.$release->version.' is a critical update, released on '.$this->_formatDate($release->date).'.</span>
                            ';
                        }
                    }

                    $testModel->failTest();
                    $testModel->value = implode(' , ', $criticalCraftUpdates);
                }

                break;

            case 'criticalPluginUpdates':
                $criticalPluginUpdates = [];

                if (!empty($this->_updates->plugins)) {
                    foreach ($this->_updates->plugins as $handle => $update) {
                        if ($update->getHasCritical()) {
                            /** @var Plugin $plugin */
                            $plugin = Craft::$app->getPlugins()->getPlugin($handle);

                            foreach ($update->releases as $release) {
                                if ($release->critical) {
                                    $criticalPluginUpdates[] = '
                                        <a href="'.$plugin->changelogUrl.'" target="_blank">'.$plugin->name.'</a> 
                                        <span class="info">Version '.$release->version.' is a critical update, released on '.$this->_formatDate($release->date).'.</span>
                                    ';
                                }
                            }
                        }
                    }
                }

                if (!empty($criticalPluginUpdates)) {
                    $testModel->failTest();
                    $testModel->value = implode(' , ', $criticalPluginUpdates);
                }

                break;

            case 'craftUpdates':
                if ($this->_updates->cms->getHasReleases()) {
                    $testModel->failTest();
                }

                break;

            case 'pluginUpdates':
                $pluginUpdates = [];

                if (!empty($this->_updates->plugins)) {
                    foreach ($this->_updates->plugins as $handle => $update) {
                        if (!empty($update->releases)) {
                            $latestRelease = $update->getLatest();

                            /** @var Plugin $plugin */
                            $plugin = Craft::$app->getPlugins()->getPlugin($handle);

                            if ($plugin !== null) {
                                $pluginUpdates[] = '
                                    <a href="'.$plugin->changelogUrl.'" target="_blank">'.$plugin->name.'</a> 
                                    <span class="info">Local version '.$plugin->version.' is '.count($update->releases).' release'.(count($update->releases) != 1 ? 's' : '').' behind latest version '.$latestRelease->version.', released on '.$this->_formatDate($latestRelease->date).'.</span>
                                ';
                            }
                        }
                    }
                }

                if (!empty($pluginUpdates)) {
                    $testModel->failTest();
                    $testModel->value = implode(' , ', $pluginUpdates);
                }

                break;

            case 'httpsControlPanel':
                if (empty($this->_cpUrlResponse['scheme']) || $this->_cpUrlResponse['scheme'] != 'https') {
                    $testModel->failTest();
                }

                break;

            case 'httpsFrontEnd':
                if (empty($this->_siteUrlResponse['scheme']) || $this->_siteUrlResponse['scheme'] != 'https') {
                    $testModel->failTest();
                }

                break;

            case 'craftFilePermissions':
                $files = [
                    '.env' => Craft::getAlias('@root/.env'),
                    'composer.json' => Craft::getAlias('@root/composer.json'),
                    'composer.lock' => Craft::getAlias('@root/composer.lock'),
                    'config/license.key' => Craft::getAlias('@config/license.key'),
                ];
                $filesFailed = [];

                foreach ($files as $key => $file) {
                    // If the file is writable by everyone
                    if (substr(decoct(fileperms($file)), -1) >= 6) {
                        $filesFailed[] = $key;
                    }
                }

                if (!empty($filesFailed)) {
                    $testModel->failTest();

                    $testModel->value = implode(', ', $filesFailed);
                }

                break;

            case 'craftFolderPermissions':
                $paths = [
                    'config/project' => Craft::getAlias('@config/project'),
                    'storage' => Craft::getAlias('@storage'),
                    'vendor' => Craft::getAlias('@vendor'),
                    'web/cpresources' => Craft::getAlias('@webroot/cpresources'),
                ];
                $pathsFailed = [];

                foreach ($paths as $key => $path) {
                    // If the path is writable by everyone
                    if (substr(decoct(fileperms($path)), -1) >= 6) {
                        $pathsFailed[] = $key;
                    }
                }

                if (!empty($pathsFailed)) {
                    $testModel->failTest();

                    $testModel->value = implode(', ', $pathsFailed);
                }

                break;

            case 'craftFoldersAboveWebRoot':
                $paths = [
                    'root' => Craft::getAlias('@root'),
                    'config' => Craft::getAlias('@config'),
                    'storage' => Craft::getAlias('@storage'),
                    'templates' => Craft::getAlias('@templates'),
                ];
                $pathsFailed = [];

                $webroot = Craft::getAlias('@webroot');

                foreach ($paths as $key => $path) {
                    // If the webroot is a substring of the path
                    if (strpos($path, $webroot) !== false) {
                        $pathsFailed[] = $key;
                    }
                }

                if (!empty($pathsFailed)) {
                    $testModel->failTest();

                    $testModel->value = implode(', ', $pathsFailed);
                }

                break;

            case 'phpVersion':
                $version = PHP_VERSION;
                $value = substr($version, 0, 3);
                $eolDate = '';

                if (isset($testModel->thresholds[$value])) {
                    if (strtotime($testModel->thresholds[$value]) < time()) {
                        $testModel->failTest();
                    }

                    $eolDate = $testModel->thresholds[$value];
                }

                $testModel->value = $version.($eolDate ? ' (until '.$eolDate.')' : '');

                break;

            case 'adminUsername':
                $user = Craft::$app->getUsers()->getUserByUsernameOrEmail('admin');

                if ($user && $user->admin) {
                    $testModel->failTest();
                }

                break;

            case 'requireEmailVerification':
                if (Craft::$app->getProjectConfig()->get('users.requireEmailVerification') === false) {
                    $testModel->failTest();
                }

                break;

            case 'webAliasInSiteBaseUrl':
                if (Craft::$app->getRequest()->isWebAliasSetDynamically) {
                    $currentSite = Craft::$app->getSites()->getCurrentSite();

                    // How this works was changed in 3.6.0
                    // https://github.com/craftcms/cms/issues/3964#issuecomment-737546660
                    // TODO: change version comparison to 3.6.0 once released
                    if (version_compare(Craft::$app->getVersion(), '3.5.99', '>=')) {
                        $unparsedBaseUrl = $currentSite->getBaseUrl(false);
                    }
                    else {
                        $unparsedBaseUrl = $currentSite->baseUrl;
                    }

                    if (strpos($unparsedBaseUrl, '@web') !== false) {
                        $testModel->failTest();
                    }
                }

                break;

            case 'webAliasInVolumeBaseUrl':
                if (Craft::$app->getRequest()->isWebAliasSetDynamically) {
                    $volumes = Craft::$app->getVolumes()->getAllVolumes();
                    $volumesFailed = [];

                    foreach ($volumes as $volume) {
                        if ($volume->hasUrls && strpos($volume->url, '@web') !== false) {
                            $volumesFailed[] = $volume->name;
                        }
                    }

                    if (!empty($volumesFailed)) {
                        $testModel->failTest();
                        $testModel->value = implode(' , ', $volumesFailed);
                    }
                }

                break;

            case 'contentSecurityPolicy':
                $value = $this->_getHeaderValue('Content-Security-Policy');
                $headerSet = !empty($value);

                if (!$headerSet) {
                    // Look for meta tag
                    preg_match('/<meta http-equiv="Content-Security-Policy" content="(.*?)"/', $this->_siteUrlResponse['body'], $matches);
                    $value = $matches[1] ?? '';
                }

                if (empty($value)){
                    $testModel->failTest();
                    $testModel->value = 'Neither Content-Security-Policy header nor meta tag are set';
                }
                else {
                    $testModel->value = 'Content-Security-Policy '.($headerSet ? 'header' : 'meta tag').' ';

                    if (strpos($value, 'unsafe-inline') !== false || strpos($value, 'unsafe-eval') !== false) {
                        $testModel->value .= 'contains "unsafe" values';
                        $testModel->warning = true;
                    }
                    else {
                        $testModel->value .= 'is set';
                    }
                }

                break;

            case 'cors':
                $value = $this->_getHeaderValue('Access-Control-Allow-Origin');

                if ($value) {
                    if ($value == '*') {
                        $testModel->failTest();
                    }
                    else {
                        if (is_array($value)) {
                            $value = implode(', ', $value);
                        }

                        $testModel->warning = true;
                    }

                    $testModel->value = '"'.$value.'"';
                }

                break;

            case 'expectCT':
                $value = $this->_getHeaderValue('Expect-CT');

                if (empty($value)) {
                    $testModel->failTest();
                }

                break;

            case 'referrerPolicy':
                $value = $this->_getHeaderValue('Referrer-Policy');

                if (empty($value)) {
                    $testModel->failTest();
                }
                else {
                    $testModel->value = '"'.$value.'"';
                }

                break;

            case 'strictTransportSecurity':
                $value = $this->_getHeaderValue('Strict-Transport-Security');

                if (empty($value)) {
                    $testModel->failTest();
                }
                else {
                    $testModel->value = '"'.$value.'"';
                }

                break;

            case 'xContentTypeOptions':
                $value = $this->_getHeaderValue('X-Content-Type-Options');

                if ($value != 'nosniff') {
                    $testModel->failTest();
                }
                else {
                    $testModel->value = '"'.$value.'"';
                }

                break;

            case 'xFrameOptions':
                $value = $this->_getHeaderValue('X-Frame-Options');

                if ($value != 'DENY' && $value != 'SAMEORIGIN') {
                    $testModel->failTest();
                }
                else {
                    $testModel->value = '"'.$value.'"';
                }

                break;

            case 'xXssProtection':
                $value = $this->_getHeaderValue('X-Xss-Protection');

                // If not set then check alternative case
                $value = $value ?: $this->_getHeaderValue('X-XSS-Protection');

                // Remove spaces and convert to lower case for comparison
                $compareValue = strtolower(str_replace(' ', '', $value));

                if ($compareValue != '1;mode=block') {
                    $testModel->failTest();
                }
                else {
                    $testModel->value = '"'.$value.'"';
                }

                break;

            case 'enableCsrfProtection':
            case 'useSecureCookies':
            case 'requireMatchingUserAgentForSession':
            case 'requireUserAgentAndIpForSession':
            case 'preventUserEnumeration':
            case 'sanitizeSvgUploads':
                if (!Craft::$app->getConfig()->getGeneral()->{$test}) {
                    $testModel->failTest();
                }

                break;

            case 'deferPublicRegistrationPassword':
            case 'devMode':
            case 'testToEmailAddress':
            case 'translationDebugOutput':
                if (Craft::$app->getConfig()->getGeneral()->{$test}) {
                    $testModel->failTest();
                }

                break;

            case 'defaultDirMode':
            case 'defaultFileMode':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};

                if ($value > $testModel->threshold) {
                    $testModel->failTest();
                }

                else {
                    $testModel->value = $value ? '0'.decoct($value) : 'null';
                }

                break;

            case 'defaultTokenDuration':
            case 'verificationCodeDuration':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};
                $seconds = ConfigHelper::durationInSeconds($value);

                if ($seconds > $testModel->threshold) {
                    $testModel->failTest();
                }

                else {
                    $testModel->value = $value;
                }

                break;

            case 'cpTrigger':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};

                if ($value == 'admin') {
                    $testModel->failTest();
                }

                break;

            case 'blowfishHashCost':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};

                if ($value < $testModel->threshold) {
                    $testModel->failTest();
                }
                else {
                    $testModel->value = $value;
                }

                break;

            case 'cooldownDuration':
            case 'invalidLoginWindowDuration':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};
                $seconds = ConfigHelper::durationInSeconds($value);

                if ($seconds < $testModel->threshold) {
                    $testModel->failTest();
                }
                else {
                    $testModel->value = $value;
                }

                break;

            case 'maxInvalidLogins':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};

                if (!$value) {
                    $testModel->failTest();
                }
                elseif ($value > $testModel->threshold) {
                    $testModel->warning = true;
                }
                else {
                    $testModel->value = $value;
                }

                break;

            case 'rememberedUserSessionDuration':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};

                if ($value) {
                    $seconds = ConfigHelper::durationInSeconds($value);

                    if ($seconds > $testModel->threshold) {
                        $testModel->failTest();
                    }
                    else {
                        $testModel->value = $value;
                    }
                }

                break;

            case 'userSessionDuration':
            case 'elevatedSessionDuration':
                $value = Craft::$app->getConfig()->getGeneral()->{$test};

                if (!$value) {
                    $testModel->failTest();
                }
                else {
                    $seconds = ConfigHelper::durationInSeconds($value);

                    if ($seconds > $testModel->threshold) {
                        $testModel->warning = true;
                    }
                    else {
                        $testModel->value = $value;
                    }
                }

                break;
        }

        return $testModel;
    }

    /**
     * Performs preps before running tests.
     *
     * @throws HttpException
     */
    private function _beforeRunTests()
    {
        // Ensure we only run this method once
        if ($this->_updates !== null) {
            return;
        }

        // Get updates, forcing a refresh
        if (empty($this->_updates)) {
            $this->_updates = Craft::$app->getUpdates()->getUpdates(true);
        }

        $client = Craft::createGuzzleClient([
            'timeout' => 10,
        ]);

        $url = '';

        try {
            $currentSite = Craft::$app->getSites()->getCurrentSite();

            // Get current site URL response
            $url = $currentSite->getBaseUrl();

            $response = $client->get($url);
            $this->_siteUrlResponse['headers'] = $response->getHeaders();
            $this->_siteUrlResponse['body'] = $response->getBody()->getContents();

            if (strpos($url, 'https://') === 0) {
                // Get redirect URL scheme of insecure URL
                $client->get(str_replace('https://', 'http://', $url), [
                    'on_stats' => function(TransferStats $stats) {
                        $this->_siteUrlResponse['scheme'] = $stats->getEffectiveUri()->getScheme();
                    },
                ]);
            }

            // Get CP URL response
            $url = UrlHelper::baseCpUrl();

            if (strpos($url, 'http') !== 0) {
                $url = trim($currentSite->getBaseUrl(), '/').'/'.Craft::$app->getConfig()->getGeneral()->cpTrigger;
            }

            if (strpos($url, 'https://') === 0) {
                // Get redirect URL scheme of insecure URL
                $client->get(str_replace('https://', 'http://', $url), [
                    'on_stats' => function(TransferStats $stats) {
                        $this->_cpUrlResponse['scheme'] = $stats->getEffectiveUri()->getScheme();
                    },
                ]);
            }
        }
        catch (GuzzleException $exception) {
            $message = Craft::t('sherlock', 'Unable to connect to "{url}". Please ensure that the site is reachable and that the system is turned on.', ['url' => $url]);

            Sherlock::$plugin->log($message);
            Sherlock::$plugin->log($exception->getMessage());

            throw new NotFoundHttpException($message);
        }
    }

    /**
     * Returns a header value.
     *
     * @param string $name
     *
     * @return string
     */
    private function _getHeaderValue(string $name): string
    {
        // Use lower-case name if it exists in the header
        if (!empty($this->_siteUrlResponse['headers'][strtolower($name)])) {
            $name = strtolower($name);
        }

        $value = $this->_siteUrlResponse['headers'][$name] ?? '';

        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        // URL decode and strip tags to make it safe to output raw
        $value = strip_tags(urldecode($value));

        return $value;
    }

    /**
     * Returns a formatted date.
     *
     * @param int|string|DateTime $date
     * @return string
     */
    private function _formatDate($date): string
    {
        return Craft::$app->getFormatter()->asDate($date, 'long');
    }
}
