#!/usr/bin/env php
<?php
/**
 * Joomla Language Package Builder
 *
 * Creates a proper Joomla language package ZIP that can be installed via the Joomla installer.
 * Works on Windows, Linux, and macOS (uses PHP ZipArchive instead of shell commands).
 *
 * The resulting ZIP contains:
 *   - pkg_{lang}.xml          (package manifest)
 *   - site_{lang}.zip         (site language files)
 *   - admin_{lang}.zip        (administrator language files)
 *   - api_{lang}.zip          (API language files)
 *
 * Usage:
 *   php build_package.php --language ms-MY --lpversion 5.4.0.1
 *   php build_package.php --language all --lpversion 5.4.0.1
 *   php build_package.php --language de --lpversion 5.4.0.1
 *   php build_package.php --help
 *
 * @package    Joomla.Language
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

if (!extension_loaded('zip')) {
    echo "ERROR: PHP zip extension is required. Enable it in php.ini.\n";
    exit(1);
}

// Parse input options
$options = getopt('', ['help', 'language:', 'lpversion:', 'v', 'jversion:']);

$showHelp  = isset($options['help']);
$language  = $options['language'] ?? 'all';
$lpVersion = $options['lpversion'] ?? false;
$verbose   = isset($options['v']);
$jVersion  = $options['jversion'] ?? '5';

if ($showHelp) {
    echo <<<HELP

Joomla Language Package Builder
================================
Creates installable Joomla language package ZIP files.

Usage:
  php build_package.php [options]

Options:
  --lpversion <version>   (required) Package version, e.g. 5.4.0.1
  --language <code|all>   (optional) Language code (ms-MY), prefix (de), or "all" (default: all)
  --jversion <4|5|6>      (optional) Joomla major version (default: 5)
  --v                     (optional) Verbose output
  --help                  Show this help

Examples:
  php build_package.php --language ms-MY --lpversion 5.4.0.1 --v
  php build_package.php --language all --lpversion 5.4.0.1
  php build_package.php --language de --lpversion 5.4.0.1

HELP;
    exit(0);
}

if (!$lpVersion) {
    echo "ERROR: --lpversion is required. Example: php build_package.php --lpversion 5.4.0.1\n";
    exit(1);
}

$baseDir       = __DIR__;
$sourceFolder  = $baseDir . '/joomla_v' . $jVersion . '/translations/package';
$outputFolder  = $baseDir . '/build/output';
$creationDate  = date('Y-m-d');

if (!is_dir($sourceFolder)) {
    echo "ERROR: Source folder not found: {$sourceFolder}\n";
    exit(1);
}

// Create output folder
if (!is_dir($outputFolder)) {
    mkdir($outputFolder, 0755, true);
}

// Collect language directories to build
$directories = [];
$allItems = scandir($sourceFolder);

foreach ($allItems as $item) {
    if ($item === '.' || $item === '..') {
        continue;
    }

    if (!is_dir($sourceFolder . DIRECTORY_SEPARATOR . $item)) {
        continue;
    }

    if ($language !== 'all' && strpos($item, $language) !== 0) {
        continue;
    }

    $directories[] = $item;
}

if (empty($directories)) {
    echo "ERROR: No language directories found matching: {$language}\n";
    exit(1);
}

msg("Building " . count($directories) . " language package(s) for Joomla {$jVersion}, version {$lpVersion}");
msg("Output folder: {$outputFolder}");
echo "\n";

$successCount = 0;
$errorCount   = 0;

foreach ($directories as $languageCode) {
    msg("===== {$languageCode} =====");

    $langSourceDir = $sourceFolder . '/' . $languageCode;
    $pkgManifest   = $langSourceDir . '/pkg_' . $languageCode . '.xml';

    // Validate source structure
    if (!file_exists($pkgManifest)) {
        msg("  SKIP: No pkg_{$languageCode}.xml manifest found");
        $errorCount++;
        continue;
    }

    // Define the 3 sub-packages
    $subPackages = [
        'site' => [
            'sourceDir' => $langSourceDir . '/language/' . $languageCode,
            'zipName'   => 'site_' . $languageCode . '.zip',
        ],
        'admin' => [
            'sourceDir' => $langSourceDir . '/administrator/language/' . $languageCode,
            'zipName'   => 'admin_' . $languageCode . '.zip',
        ],
        'api' => [
            'sourceDir' => $langSourceDir . '/api/language/' . $languageCode,
            'zipName'   => 'api_' . $languageCode . '.zip',
        ],
    ];

    // Create a temporary folder for this language
    $tmpDir = $outputFolder . '/tmp_' . $languageCode . '_' . time();
    mkdir($tmpDir, 0755, true);

    $hasError = false;

    // Generate the localise.php class name
    $localise = ucfirst(str_replace('-', '_', $languageCode)) . 'Localise';

    // Step 1: Create each inner sub-package ZIP
    foreach ($subPackages as $type => $subPkg) {
        if (!is_dir($subPkg['sourceDir'])) {
            msg("  WARNING: {$type} source folder not found: {$subPkg['sourceDir']}");
            // API folder might not exist for all languages, create empty zip
            $zip = new ZipArchive();
            $zipPath = $tmpDir . '/' . $subPkg['zipName'];
            if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                $zip->close();
            }
            continue;
        }

        msg("  Creating {$subPkg['zipName']}...");
        $zipPath = $tmpDir . '/' . $subPkg['zipName'];

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            msg("  ERROR: Cannot create ZIP: {$zipPath}");
            $hasError = true;
            break;
        }

        // Add all files from the sub-package source directory
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($subPkg['sourceDir'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $fileCount = 0;
        foreach ($files as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                // Files go at the root of the zip (no subdirectory)
                $relativePath = $file->getFilename();

                // Read file contents and apply replacements
                $content = file_get_contents($filePath);

                if ($file->getExtension() === 'xml') {
                    $content = str_replace('<version/>', '<version>' . $lpVersion . '</version>', $content);
                    $content = str_replace('<creationDate/>', '<creationDate>' . $creationDate . '</creationDate>', $content);
                }

                if ($file->getFilename() === 'localise.php') {
                    $content = str_replace('En_GBLocalise', $localise, $content);
                }

                $zip->addFromString($relativePath, $content);
                $fileCount++;
            }
        }

        $zip->close();
        msg("    Added {$fileCount} files");
    }

    if ($hasError) {
        msg("  ERROR: Skipping {$languageCode} due to errors");
        rmdirRecursive($tmpDir);
        $errorCount++;
        continue;
    }

    // Step 2: Prepare the package manifest XML
    msg("  Preparing pkg_{$languageCode}.xml...");
    $manifestContent = file_get_contents($pkgManifest);
    $manifestContent = str_replace('<version/>', '<version>' . $lpVersion . '</version>', $manifestContent);
    $manifestContent = str_replace('<creationDate/>', '<creationDate>' . $creationDate . '</creationDate>', $manifestContent);
    file_put_contents($tmpDir . '/pkg_' . $languageCode . '.xml', $manifestContent);

    // Step 3: Create the final package ZIP
    $finalZipName = $languageCode . '_joomla_lang_' . $lpVersion . '.zip';
    $finalZipPath = $outputFolder . '/' . $finalZipName;

    msg("  Creating final package: {$finalZipName}...");

    $zip = new ZipArchive();
    if ($zip->open($finalZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        msg("  ERROR: Cannot create final ZIP: {$finalZipPath}");
        rmdirRecursive($tmpDir);
        $errorCount++;
        continue;
    }

    // Add all files from tmp folder to the root of the zip
    $tmpFiles = scandir($tmpDir);
    foreach ($tmpFiles as $f) {
        if ($f === '.' || $f === '..') continue;
        $fullPath = $tmpDir . '/' . $f;
        if (is_file($fullPath)) {
            $zip->addFile($fullPath, $f);
        }
    }

    $zip->close();

    // Cleanup tmp
    rmdirRecursive($tmpDir);

    $finalSize = round(filesize($finalZipPath) / 1024, 1);
    msg("  OK: {$finalZipName} ({$finalSize} KB)");
    $successCount++;
    echo "\n";
}

echo "========================================\n";
echo "Done! {$successCount} package(s) built";
if ($errorCount > 0) {
    echo ", {$errorCount} error(s)";
}
echo "\n";
echo "Output: {$outputFolder}\n";

// ============================================
// Helper functions
// ============================================

function msg($text) {
    global $verbose;
    // Always show messages (use --v for extra debug in future)
    echo $text . "\n";
}

function rmdirRecursive($dir) {
    if (!is_dir($dir)) return;

    $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    $it = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($dir);
}
