<?php
namespace TYPO3\ClassAliasLoader;

/*
 * This file is part of the class alias loader package.
 *
 * (c) Helmut Hummel <info@helhum.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * This class loops over all packages that are installed by composer and
 * looks for configured class alias maps (in composer.json).
 * If at least one is found, the vendor/autoload.php file is rewritten to amend the composer class loader.
 * Otherwise it does nothing.
 */
class ClassAliasMapGenerator
{
    /**
     * @param \Composer\Script\Event $event
     * @return bool
     * @throws \Exception
     */
    public static function generateAliasMap(\Composer\Script\Event $event)
    {
        $composer = $event->getComposer();
        $config = $composer->getConfig();

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $basePath = self::extractBasePath($config);
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));
        $targetDir = $vendorPath . '/composer';
        $filesystem->ensureDirectoryExists($targetDir);

        $mainPackage = $composer->getPackage();
        $autoLoadGenerator = $composer->getAutoloadGenerator();
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $packageMap = $autoLoadGenerator->buildPackageMap($composer->getInstallationManager(), $mainPackage, $localRepo->getCanonicalPackages());

        $aliasToClassNameMapping = array();
        $classNameToAliasMapping = array();
        $classAliasMappingFound = false;

        foreach ($packageMap as $item) {
            /** @var PackageInterface $package */
            list($package, $installPath) = $item;
            $aliasLoaderConfig = self::getAliasLoaderConfigFromPackage($package, $event);
            if (!empty($aliasLoaderConfig['class-alias-maps'])) {
                if (!is_array($aliasLoaderConfig['class-alias-maps'])) {
                    throw new \Exception('"class-alias-maps" must be an array');
                }
                foreach ($aliasLoaderConfig['class-alias-maps'] as $mapFile) {
                    $mapFilePath = ($installPath ?: $basePath) . '/' . $filesystem->normalizePath($mapFile);
                    if (!is_file($mapFilePath)) {
                        $event->getIo()->writeError(sprintf('The class alias map file "%s" configured in package "%s" was not found!', $mapFile, $package->getName()));
                    } else {
                        $packageAliasMap = require $mapFilePath;
                        if (!is_array($packageAliasMap)) {
                            throw new \Exception('"Class alias maps" must return an array', 1422625075);
                        }
                        if (!empty($packageAliasMap)) {
                            $classAliasMappingFound = true;
                        }
                        foreach ($packageAliasMap as $aliasClassName => $className) {
                            $lowerCasedAliasClassName = strtolower($aliasClassName);
                            $aliasToClassNameMapping[$lowerCasedAliasClassName] = $className;
                            $classNameToAliasMapping[$className][$lowerCasedAliasClassName] = $lowerCasedAliasClassName;
                        }
                    }
                }
            }
        }

        $mainPackageAliasLoaderConfig = self::getAliasLoaderConfigFromPackage($mainPackage, $event);
        $alwaysAddAliasLoader = $mainPackageAliasLoaderConfig['always-add-alias-loader'];
        $caseSensitiveClassLoading = $mainPackageAliasLoaderConfig['autoload-case-sensitivity'];

        if (!$alwaysAddAliasLoader && !$classAliasMappingFound && $caseSensitiveClassLoading) {
            // No mapping found in any package and no insensitive class loading active. We return early and skip rewriting
            // Unless user configured alias loader to be always added
            return false;
        }

        $caseSensitiveClassLoadingString = $caseSensitiveClassLoading ? 'true' : 'false';
        $event->getIO()->write('<info>Generating ' . ($classAliasMappingFound ? ' ' : 'empty ') . 'class alias map file</info>');
        self::generateAliasMapFile($aliasToClassNameMapping, $classNameToAliasMapping, $targetDir);

        $suffix = null;
        if (!$config->get('autoloader-suffix') && is_readable($vendorPath . '/autoload.php')) {
            $content = file_get_contents($vendorPath . '/autoload.php');
            if (preg_match('{ComposerAutoloaderInit([^:\s]+)::}', $content, $match)) {
                $suffix = $match[1];
            }
        }

        if (!$suffix) {
            $suffix = $config->get('autoloader-suffix') ?: md5(uniqid('', true));
        }

        $prependAutoloader = $config->get('prepend-autoloader') === false ? 'false' : 'true';

        $aliasLoaderInitClassContent = <<<EOF
<?php

// autoload_alias_loader_real.php @generated by typo3/class-alias-loader

class ClassAliasLoaderInit$suffix {

    private static \$loader;

    public static function initializeClassAliasLoader(\$composerClassLoader) {
        if (null !== self::\$loader) {
            return self::\$loader;
        }
        self::\$loader = \$composerClassLoader;

        \$classAliasMap = require __DIR__ . '/autoload_classaliasmap.php';
        \$classAliasLoader = new TYPO3\ClassAliasLoader\ClassAliasLoader(\$composerClassLoader);
        \$classAliasLoader->setAliasMap(\$classAliasMap);
        \$classAliasLoader->setCaseSensitiveClassLoading($caseSensitiveClassLoadingString);
        \$classAliasLoader->register($prependAutoloader);

        TYPO3\ClassAliasLoader\ClassAliasMap::setClassAliasLoader(\$classAliasLoader);

        return self::\$loader;
    }
}

EOF;
        file_put_contents($targetDir . '/autoload_alias_loader_real.php', $aliasLoaderInitClassContent);

        if (!$caseSensitiveClassLoading) {
            $event->getIO()->write('<info>Re-writing class map to support case insensitive class loading</info>');
            $flags = $event->getFlags();
            $optimize = !empty($flags['optimize']) || $config->get('optimize-autoloader') || $config->get('classmap-authoritative');
            if (!$optimize) {
                $event->getIO()->write('<warning>Case insensitive class loading only works reliably if you use the optimize class loading feature of composer</warning>');
            }
            self::rewriteClassMapWithLowerCaseClassNames($targetDir);
        }

        $event->getIO()->write('<info>Inserting class alias loader into main autoload.php file</info>');
        static::modifyMainAutoloadFile($vendorPath . '/autoload.php', $suffix);

        return true;
    }

    /**
     * @param PackageInterface $package
     * @param \Composer\Script\Event $event
     * @return array
     * @TODO: refactor into own config object
     */
    protected static function getAliasLoaderConfigFromPackage(PackageInterface $package, $event)
    {
        $extraConfig = self::handleDeprecatedConfigurationInPackage($package, $event);
        $aliasLoaderConfig = array(
                'class-alias-maps' => array(),
                'always-add-alias-loader' => false,
                'autoload-case-sensitivity' => true
        );
        if (isset($extraConfig['typo3/class-alias-loader']['class-alias-maps'])) {
            $aliasLoaderConfig['class-alias-maps'] = (array)$extraConfig['typo3/class-alias-loader']['class-alias-maps'];
        }
        if (isset($extraConfig['typo3/class-alias-loader']['always-add-alias-loader'])) {
            $aliasLoaderConfig['always-add-alias-loader'] = (bool)$extraConfig['typo3/class-alias-loader']['always-add-alias-loader'];
        }
        if (isset($extraConfig['typo3/class-alias-loader']['autoload-case-sensitivity'])) {
            $aliasLoaderConfig['autoload-case-sensitivity'] = (bool)$extraConfig['typo3/class-alias-loader']['autoload-case-sensitivity'];
        }

        return $aliasLoaderConfig;
    }

    /**
     * Ensures backwards compatibility for packages which used helhum/class-alias-loader
     *
     * @param PackageInterface $package
     * @param \Composer\Script\Event $event
     * @return array
     */
    protected static function handleDeprecatedConfigurationInPackage(PackageInterface $package, $event)
    {
        $extraConfig = $package->getExtra();
        if (!isset($extraConfig['typo3/class-alias-loader'])) {
            if (isset($extraConfig['helhum/class-alias-loader'])) {
                $extraConfig['typo3/class-alias-loader'] = $extraConfig['helhum/class-alias-loader'];
                $event->getIO()
                      ->write(sprintf('The package "%s" uses "helhum/class-alias-loader" section to define class alias maps, which is deprecated. Please use "typo3/class-alias-loader" instead!',
                                      $package->getName()));
            } else {
                $extraConfig['typo3/class-alias-loader'] = array();
                if (isset($extraConfig['class-alias-maps'])) {
                    $extraConfig['typo3/class-alias-loader']['class-alias-maps'] = $extraConfig['class-alias-maps'];
                    $event->getIO()
                          ->write(sprintf('The package "%s" uses "class-alias-maps" section on top level, which is deprecated. Please move this config below the top level key "typo3/class-alias-loader" instead!',
                                          $package->getName()));
                }
                if (isset($extraConfig['autoload-case-sensitivity'])) {
                    $extraConfig['typo3/class-alias-loader']['autoload-case-sensitivity'] = $extraConfig['autoload-case-sensitivity'];
                    $event->getIO()
                          ->write(sprintf('The package "%s" uses "autoload-case-sensitivity" section on top level, which is deprecated. Please move this config below the top level key "typo3/class-alias-loader" instead!',
                                          $package->getName()));
                }
            }
        }
        return $extraConfig;
    }

    /**
     * @param $autoloadFile
     * @param string $suffix
     */
    protected static function modifyMainAutoloadFile($autoloadFile, $suffix)
    {
        $originalAutoloadFileContent = file_get_contents($autoloadFile);
        preg_match('/return ComposerAutoloaderInit[^;]*;/', $originalAutoloadFileContent, $matches);
        $originalAutoloadFileContent = str_replace($matches[0], '', $originalAutoloadFileContent);
        $composerClassLoaderInit = str_replace(array('return ', ';'), '', $matches[0]);
        $autoloadFileContent = <<<EOF
$originalAutoloadFileContent

// autoload.php @generated by typo3/class-alias-loader

require_once __DIR__ . '/composer/autoload_alias_loader_real.php';

return ClassAliasLoaderInit$suffix::initializeClassAliasLoader($composerClassLoaderInit);

EOF;

        file_put_contents($autoloadFile, $autoloadFileContent);

    }

    /**
     * @param array $aliasToClassNameMapping
     * @param array $classNameToAliasMapping
     * @param string $targetDir
     */
    protected static function generateAliasMapFile(array $aliasToClassNameMapping, array $classNameToAliasMapping, $targetDir)
    {
        $exportArray = array(
            'aliasToClassNameMapping' => $aliasToClassNameMapping,
            'classNameToAliasMapping' => $classNameToAliasMapping
        );

        $fileContent = '<?php' . chr(10) . 'return ';
        $fileContent .= var_export($exportArray, true);
        $fileContent .= ';';

        file_put_contents($targetDir . '/autoload_classaliasmap.php', $fileContent);
    }

    /**
     * Rewrites the class map to have lowercased keys to be able to load classes with wrong casing
     * Defaults to case sensitivity (composer loader default)
     *
     * @param string $targetDir
     */
    protected static function rewriteClassMapWithLowerCaseClassNames($targetDir)
    {
        $classMapContents = file_get_contents($targetDir . '/autoload_classmap.php');
        $classMapContents = preg_replace_callback('/    \'[^\']*\' => /', function ($match) {
            return strtolower($match[0]);
        }, $classMapContents);
        file_put_contents($targetDir . '/autoload_classmap.php', $classMapContents);
    }


    /**
     * Extracts the bas path out of composer config
     *
     * @param \Composer\Config $config
     * @return mixed
     */
    protected static function extractBasePath(\Composer\Config $config) {
        $reflectionClass = new \ReflectionClass($config);
        $reflectionProperty = $reflectionClass->getProperty('baseDir');
        $reflectionProperty->setAccessible(true);
        return $reflectionProperty->getValue($config);
    }

}
