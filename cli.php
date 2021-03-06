#!/usr/bin/env php
<?php

use dokuwiki\Extension\CLIPlugin;
use dokuwiki\Extension\PluginController;
use dokuwiki\plugin\dev\LangProcessor;
use dokuwiki\plugin\dev\SVGIcon;
use splitbrain\phpcli\Exception as CliException;
use splitbrain\phpcli\Options;

/**
 * @license GPL2
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class cli_plugin_dev extends CLIPlugin
{
    /**
     * Register options and arguments on the given $options object
     *
     * @param Options $options
     * @return void
     */
    protected function setup(Options $options)
    {
        $options->useCompactHelp();
        $options->setHelp(
            "CLI to help with DokuWiki plugin and template development.\n\n" .
            "Run this script from within the extension's directory."
        );

        $options->registerCommand('init', 'Initialize a new plugin or template in the current (empty) directory.');
        $options->registerCommand('addTest', 'Add the testing framework files and a test. (_test/)');
        $options->registerArgument('test', 'Optional name of the new test. Defaults to the general test.', false,
            'addTest');
        $options->registerCommand('addConf', 'Add the configuration files. (conf/)');
        $options->registerCommand('addLang', 'Add the language files. (lang/)');

        $types = PluginController::PLUGIN_TYPES;
        array_walk(
            $types,
            function (&$item) {
                $item = $this->colors->wrap($item, $this->colors::C_BROWN);
            }
        );

        $options->registerCommand('addComponent', 'Add a new plugin component.');
        $options->registerArgument('type', 'Type of the component. Needs to be one of ' . join(', ', $types), true,
            'addComponent');
        $options->registerArgument('name', 'Optional name of the component. Defaults to a base component.', false,
            'addComponent');

        $options->registerCommand('deletedFiles', 'Create the list of deleted files based on the git history.');
        $options->registerCommand('rmObsolete', 'Delete obsolete files.');

        $prefixes = array_keys(SVGIcon::SOURCES);
        array_walk(
            $prefixes,
            function (&$item) {
                $item = $this->colors->wrap($item, $this->colors::C_BROWN);
            }
        );

        $options->registerCommand('downloadSvg', 'Download an SVG file from a known icon repository.');
        $options->registerArgument('prefix:name',
            'Colon-prefixed name of the icon. Available prefixes: ' . join(', ', $prefixes), true, 'downloadSvg');
        $options->registerArgument('output', 'File to save, defaults to <name>.svg in current dir', false,
            'downloadSvg');
        $options->registerOption('keep-ns', 'Keep the SVG namespace. Use when the file is not inlined into HTML.', 'k',
            false, 'downloadSvg');

        $options->registerCommand('cleanSvg', 'Clean a existing SVG files to reduce their file size.');
        $options->registerArgument('files...', 'The files to clean (will be overwritten)', true, 'cleanSvg');
        $options->registerOption('keep-ns', 'Keep the SVG namespace. Use when the file is not inlined into HTML.', 'k',
            false, 'cleanSvg');

        $options->registerCommand('cleanLang',
            'Clean language files from unused language strings. Detecting which strings are truly in use may ' .
            'not always correctly work. Use with caution.');
    }

    /** @inheritDoc */
    protected function main(Options $options)
    {
        $args = $options->getArgs();

        switch ($options->getCmd()) {
            case 'init':
                return $this->cmdInit();
            case 'addTest':
                $test = array_shift($args);
                return $this->cmdAddTest($test);
            case 'addConf':
                return $this->cmdAddConf();
            case 'addLang':
                return $this->cmdAddLang();
            case 'addComponent':
                $type = array_shift($args);
                $component = array_shift($args);
                return $this->cmdAddComponent($type, $component);
            case 'deletedFiles':
                return $this->cmdDeletedFiles();
            case 'rmObsolete':
                return $this->cmdRmObsolete();
            case 'downloadSvg':
                $ident = array_shift($args);
                $save = array_shift($args);
                $keep = $options->getOpt('keep-ns', false);
                return $this->cmdDownloadSVG($ident, $save, $keep);
            case 'cleanSvg':
                $keep = $options->getOpt('keep-ns', false);
                return $this->cmdCleanSVG($args, $keep);
            case 'cleanLang':
                return $this->cmdCleanLang();
            default:
                $this->error('Unknown command');
                echo $options->help();
                return 0;
        }
    }

    /**
     * Get the extension name from the current working directory
     *
     * @throws CliException if something's wrong
     * @param string $dir
     * @return string[] name, type
     */
    protected function getTypedNameFromDir($dir)
    {
        $pdir = fullpath(DOKU_PLUGIN);
        $tdir = fullpath(tpl_incdir() . '../');

        if (strpos($dir, $pdir) === 0) {
            $ldir = substr($dir, strlen($pdir));
            $type = 'plugin';
        } elseif (strpos($dir, $tdir) === 0) {
            $ldir = substr($dir, strlen($tdir));
            $type = 'template';
        } else {
            throw new CliException('Current directory needs to be in plugin or template directory');
        }

        $ldir = trim($ldir, '/');

        if (strpos($ldir, '/') !== false) {
            throw new CliException('Current directory has to be main extension directory');
        }

        return [$ldir, $type];
    }

    /**
     * Interactively ask for a value from the user
     *
     * @param string $prompt
     * @param bool $cache cache given value for next time?
     * @return string
     */
    protected function readLine($prompt, $cache = false)
    {
        $value = '';
        $default = '';
        $cachename = getCacheName($prompt, '.readline');
        if ($cache && file_exists($cachename)) {
            $default = file_get_contents($cachename);
        }

        while ($value === '') {
            echo $prompt;
            if ($default) echo ' [' . $default . ']';
            echo ': ';

            $fh = fopen('php://stdin', 'r');
            $value = trim(fgets($fh));
            fclose($fh);

            if ($value === '') $value = $default;
        }

        if ($cache) {
            file_put_contents($cachename, $value);
        }

        return $value;
    }

    /**
     * Download a skeleton file and do the replacements
     *
     * @param string $skel Skeleton relative to the skel dir in the repo
     * @param string $target Target file relative to the main directory
     * @param array $replacements
     */
    protected function loadSkeleton($skel, $target, $replacements)
    {
        if (file_exists($target)) {
            $this->error($target . ' already exists');
            return;
        }

        $base = 'https://raw.githubusercontent.com/dokufreaks/dokuwiki-plugin-wizard/master/skel/';
        $http = new \dokuwiki\HTTP\DokuHTTPClient();
        $content = $http->get($base . $skel);

        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );

        io_makeFileDir($target);
        file_put_contents($target, $content);
        $this->success('Added ' . $target);
    }

    /**
     * Prepare the string replacements
     *
     * @param array $replacements override defaults
     * @return array
     */
    protected function prepareReplacements($replacements = [])
    {
        // defaults
        $data = [
            '@@AUTHOR_NAME@@' => '',
            '@@AUTHOR_MAIL@@' => '',
            '@@PLUGIN_NAME@@' => '',
            '@@PLUGIN_DESC@@' => '',
            '@@PLUGIN_URL@@' => '',
            '@@PLUGIN_TYPE@@' => '',
            '@@INSTALL_DIR@@' => 'plugins',
            '@@DATE@@' => date('Y-m-d'),
        ];

        // load from existing plugin.info
        $dir = fullpath(getcwd());
        [$name, $type] = $this->getTypedNameFromDir($dir);
        if (file_exists("$type.info.txt")) {
            $info = confToHash("$type.info.txt");
            $data['@@AUTHOR_NAME@@'] = $info['author'];
            $data['@@AUTHOR_MAIL@@'] = $info['email'];
            $data['@@PLUGIN_DESC@@'] = $info['desc'];
            $data['@@PLUGIN_URL@@'] = $info['url'];
        }
        $data['@@PLUGIN_NAME@@'] = $name;
        $data['@@PLUGIN_TYPE@@'] = $type;

        if ($type == 'template') {
            $data['@@INSTALL_DIR@@'] = 'tpl';
        }

        // merge given overrides
        $data = array_merge($data, $replacements);

        // set inherited defaults
        if (empty($data['@@PLUGIN_URL@@'])) {
            $data['@@PLUGIN_URL@@'] =
                'https://www.dokuwiki.org/' .
                $data['@@PLUGIN_TYPE@@'] . ':' .
                $data['@@PLUGIN_NAME@@'];
        }

        return $data;
    }

    /**
     * Replacements needed for action components.
     *
     * Not cool but that' what we need currently
     *
     * @return string[]
     */
    protected function actionReplacements()
    {
        $fn = 'handleEventName';
        $register = '        $controller->register_hook(\'EVENT_NAME\', \'AFTER|BEFORE\', $this, \'' . $fn . '\');';
        $handler = '    public function ' . $fn . '(Doku_Event $event, $param)' . "\n"
            . "    {\n"
            . "    }\n";

        return [
            '@@REGISTER@@' => $register . "\n   ",
            '@@HANDLERS@@' => $handler,
        ];
    }

    /**
     * Delete the given file if it exists
     *
     * @param string $file
     */
    protected function deleteFile($file)
    {
        if (!file_exists($file)) return;
        if (@unlink($file)) {
            $this->success('Delete ' . $file);
        }
    }

    /**
     * Run git with the given arguments and return the output
     *
     * @throws CliException when the command can't be run
     * @param string ...$args
     * @return string[]
     */
    protected function git(...$args)
    {
        $args = array_map('escapeshellarg', $args);
        $cmd = 'git ' . join(' ', $args);
        $output = [];
        $result = 0;

        $this->info($cmd);
        $last = exec($cmd, $output, $result);
        if ($last === false || $result !== 0) {
            throw new CliException('Running git failed');
        }

        return $output;
    }

    // region Commands

    /**
     * Intialize the current directory as a plugin or template
     *
     * @return int
     */
    protected function cmdInit()
    {
        $dir = fullpath(getcwd());
        if ((new FilesystemIterator($dir))->valid()) {
            throw new CliException('Current directory needs to be empty');
        }

        [$name, $type] = $this->getTypedNameFromDir($dir);
        $user = $this->readLine('Your Name', true);
        $mail = $this->readLine('Your E-Mail', true);
        $desc = $this->readLine('Short description');

        $replacements = [
            '@@AUTHOR_NAME@@' => $user,
            '@@AUTHOR_MAIL@@' => $mail,
            '@@PLUGIN_NAME@@' => $name,
            '@@PLUGIN_DESC@@' => $desc,
            '@@PLUGIN_TYPE@@' => $type,
        ];
        $replacements = $this->prepareReplacements($replacements);

        $this->loadSkeleton('info.skel', $type . '.info.txt', $replacements);
        $this->loadSkeleton('README.skel', 'README', $replacements); // fixme needs to be type specific
        $this->loadSkeleton('LICENSE.skel', 'LICENSE', $replacements);

        try {
            $this->git('init');
        } catch (CliException $e) {
            $this->error($e->getMessage());
        }

        return 0;
    }

    /**
     * Add test framework
     *
     * @param string $test Name of the Test to add
     * @return int
     */
    protected function cmdAddTest($test = '')
    {
        $test = ucfirst(strtolower($test));

        $replacements = $this->prepareReplacements(['@@TEST@@' => $test]);
        $this->loadSkeleton('.github/workflows/phpTestLinux.skel', '.github/workflows/phpTestLinux.yml', $replacements);
        if ($test) {
            $this->loadSkeleton('_test/StandardTest.skel', '_test/' . $test . 'Test.php', $replacements);
        } else {
            $this->loadSkeleton('_test/GeneralTest.skel', '_test/GeneralTest.php', $replacements);
        }

        return 0;
    }

    /**
     * Add configuration
     *
     * @return int
     */
    protected function cmdAddConf()
    {
        $replacements = $this->prepareReplacements();
        $this->loadSkeleton('conf/default.skel', 'conf/default.php', $replacements);
        $this->loadSkeleton('conf/metadata.skel', 'conf/metadata.php', $replacements);
        if (is_dir('lang')) {
            $this->loadSkeleton('lang/settings.skel', 'lang/en/settings.php', $replacements);
        }

        return 0;
    }

    /**
     * Add language
     *
     * @return int
     */
    protected function cmdAddLang()
    {
        $replacements = $this->prepareReplacements();
        $this->loadSkeleton('lang/lang.skel', 'lang/en/lang.php', $replacements);
        if (is_dir('conf')) {
            $this->loadSkeleton('lang/settings.skel', 'lang/en/settings.php', $replacements);
        }

        return 0;
    }

    /**
     * Add another component to the plugin
     *
     * @param string $type
     * @param string $component
     */
    protected function cmdAddComponent($type, $component = '')
    {
        $dir = fullpath(getcwd());
        list($plugin, $extension) = $this->getTypedNameFromDir($dir);
        if ($extension != 'plugin') throw  new CliException('Components can only be added to plugins');
        if (!in_array($type, PluginController::PLUGIN_TYPES)) {
            throw new CliException('Invalid type ' . $type);
        }

        if ($component) {
            $path = $type . '/' . $component . '.php';
            $class = $type . '_plugin_' . $plugin . '_' . $component;
            $self = $plugin . '_' . $component;
        } else {
            $path = $type . '.php';
            $class = $type . '_plugin_' . $plugin;
            $self = $plugin;
        }

        $replacements = $this->actionReplacements();
        $replacements['@@PLUGIN_COMPONENT_NAME@@'] = $class;
        $replacements['@@SYNTAX_COMPONENT_NAME@@'] = $self;
        $replacements = $this->prepareReplacements($replacements);
        $this->loadSkeleton($type . '.skel', $path, $replacements);

        return 0;
    }

    /**
     * Generate a list of deleted files from git
     *
     * @link https://stackoverflow.com/a/6018049/172068
     */
    protected function cmdDeletedFiles()
    {
        if (!is_dir('.git')) throw new CliException('This extension seems not to be managed by git');

        $output = $this->git('log', '--no-renames', '--pretty=format:', '--name-only', '--diff-filter=D');
        $output = array_map('trim', $output);
        $output = array_filter($output);
        $output = array_unique($output);
        $output = array_filter($output, function ($item) {
            return !file_exists($item);
        });
        sort($output);

        if (!count($output)) {
            $this->info('No deleted files found');
            return 0;
        }

        $content = "# This is a list of files that were present in previous releases\n" .
            "# but were removed later. They should not exist in your installation.\n" .
            join("\n", $output) . "\n";

        file_put_contents('deleted.files', $content);
        $this->success('written deleted.files');
        return 0;
    }

    /**
     * Remove files that shouldn't be here anymore
     */
    protected function cmdRmObsolete()
    {
        $this->deleteFile('_test/general.test.php');
        $this->deleteFile('.travis.yml');

        return 0;
    }

    /**
     * Download a remote icon
     *
     * @param string $ident
     * @param string $save
     * @param bool $keep
     * @return int
     * @throws Exception
     */
    protected function cmdDownloadSVG($ident, $save = '', $keep = false)
    {
        $svg = new SVGIcon($this);
        $svg->keepNamespace($keep);
        return (int)$svg->downloadRemoteIcon($ident, $save);
    }

    /**
     * @param string[] $files
     * @param bool $keep
     * @return int
     * @throws Exception
     */
    protected function cmdCleanSVG($files, $keep = false)
    {
        $svg = new SVGIcon($this);
        $svg->keepNamespace($keep);

        $ok = true;
        foreach ($files as $file) {
            $ok = $ok && $svg->cleanSVGFile($file);
        }
        return (int)$ok;
    }

    /**
     * @return int
     */
    protected function cmdCleanLang()
    {
        $lp = new LangProcessor($this);

        $files = glob('./lang/*/lang.php');
        foreach ($files as $file) {
            $lp->processLangFile($file);
        }

        $files = glob('./lang/*/settings.php');
        foreach ($files as $file) {
            $lp->processSettingsFile($file);
        }

        return 0;
    }

    //endregion
}
