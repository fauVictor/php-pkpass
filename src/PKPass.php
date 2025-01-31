<?php

/**
 * Copyright (c) 2017, Thomas Schoffelen BV.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

namespace PKPass;

use Exception;
use ZipArchive;

/**
 * Class PKPass.
 */
class PKPass
{
    /**
     * Holds the path to the certificate
     * @var string $certPath
     */
    protected string $certPath;

    /**
     * Name of the downloaded file.
     * @var string $name
     */
    protected string $name;

    /**
     * Holds the files to include in the .pkpass
     * @var string[] $files
     */
    protected array $files = [];

    /**
     * Holds the remote file urls to include in the .pkpass
     * @var string[] $remote_file_urls
     */
    protected array $remote_file_urls = [];

    /**
     * Holds the json
     * @var string $json supposed to be a json object
     */
    protected string $json;

    /**
     * Holds the SHAs of the $files array
     * @var string[] $shas
     */
    protected array $shas;

    /**
     * Holds the password to the certificate
     * @var string $certPass
     */
    protected string $certPass = '';

    /**
     * Holds the path to the WWDR Intermediate certificate
     * @var string $wwdrCertPath
     */
    protected string $wwdrCertPath = '';

    /**
     * Holds the path to a temporary folder with trailing slash.
     * @var string $tempPath
     */
    protected string $tempPath;

    /**
     * Holds error info if an error occurred.
     * @var string $errorMessage
     */
    private string $errorMessage = '';

    /**
     * Holds an auto-generated uniqid to prevent overwriting other processes pass
     * files.
     * @var string $uniqid
     */
    private string $uniqid = '';

    /**
     * Holds array of localization details
     * @var string[] $locales
     */
    protected array $locales = [];

    /**
     * PKPass constructor.
     *
     * @param string $certPath Path to certificate.p12
     * @param string $certPass
     * @param array $JSON
     */
    public function __construct(string $certPath = '', string $certPass = '', array $JSON = [])
    {
        $this->tempPath = sys_get_temp_dir() . '/';  // Must end with slash!
        $this->wwdrCertPath = __DIR__ . '/Certificate/AppleWWDRCA.pem'; // TODO enable


        if(empty($certPath)) {
            $this->setCertificate($certPath);
        }
        if(empty($certPass)) {
            $this->setCertificatePassword($certPass);
        }
        if(empty($JSON)) {
            $this->setData($JSON);
        }
    }

    /**
     * Sets the path to a certificate
     * Parameter: string, path to certificate
     * Return: boolean, always true.
     *
     * @param $path
     *
     * @return bool
     */
    public function setCertificate($path): bool
    {
        $this->certPath = $path;

        return true;
    }

    /**
     * Sets the certificate's password
     * Parameter: string, password to the certificate
     * Return: boolean, always true.
     *
     * @param $certificatePassword
     *
     * @return bool
     */
    public function setCertificatePassword($certificatePassword): bool
    {
        $this->certPass = $certificatePassword;

        return true;
    }

    /**
     * Sets the path to the WWDR Intermediate certificate
     * Parameter: string, path to certificate
     * Return: boolean, always true.
     *
     * @param $path
     *
     * @return bool
     */
    public function setWWDRCertificatePath($path): bool
    {
        $this->wwdrCertPath = $path;

        return true;
    }

    /**
     * Set the path to the temporary directory.
     *
     * @param string $path Path to temporary directory
     * @return bool
     */
    public function setTempPath(string $path): bool
    {
        if(is_dir($path)) {
            $this->tempPath = rtrim($path, '/') . '/';

            return true;
        }

        return false;
    }

    /**
     * Set JSON for pass.
     *
     * @deprecated in favor of `setData()`
     *
     * @param string|array $json
     * @return bool
     */
    public function setJSON($json): bool
    {
        return $this->setData($json);
    }

    /**
     * Set pass data.
     *
     * @param array $data
     * @return bool
     */
    public function setData(array $data): bool
    {
        return $this->json = json_encode($data);
    }

    /**
     * Add dictionary of strings for transilation.
     *
     * @param string $language language project need to be added
     * @param array $strings key value pair of transilation strings
     *     (default is equal to [])
     * @return bool
     *
     * @throws Exception
     */
    public function addLocaleStrings(string $language, array $strings = []): bool
    {
        if(!is_array($strings) || empty($strings)) {
            throw new Exception('Translation strings empty or not an array');
        }
        $dictionary = "";
        foreach($strings as $key => $value) {
            $dictionary .= '"'. $this->escapeLocaleString($key) .'" = "'. $this->escapeLocaleString($value) .'";'. PHP_EOL;
        }
        $this->locales[$language] = $dictionary;

        return true;
    }

    /**
     * Add a file to the file array.
     *
     * @param string $language language for which file to be added
     * @param string $path Path to file
     * @param string|null $name Filename to use in pass archive
     *     (default is equal to $path)
     * @return bool
     * @throws Exception
     */
    public function addLocaleFile(string $language, string $path, string $name = null): bool
    {
        if(file_exists($path)) {
            $name = ($name === null) ? basename($path) : $name;
            $this->files[$language .'.lproj/'. $name] = $path;

            return true;
        }

        throw new Exception(sprintf('File %s does not exist.', $path));
    }

    /**
     * Add a file to the file array.
     *
     * @param string $path Path to file
     * @param string|null $name Filename to use in pass archive
     *     (default is equal to $path)
     * @return bool
     * @throws Exception
     */
    public function addFile(string $path, string $name = null): bool
    {
        if(file_exists($path)) {
            $name = ($name === null) ? basename($path) : $name;
            $this->files[$name] = $path;

            return true;
        }

        throw new Exception(sprintf('File %s does not exist.', $path));
    }

    /**
     * Add a file from a url to the remote file urls array.
     *
     * @param string $url URL to file
     * @param string|null $name Filename to use in pass archive
     *     (default is equal to $url)
     * @return bool
     */
    public function addRemoteFile(string $url, string $name = null): bool
    {
      $name = ($name === null) ? basename($url) : $name;
      $this->remote_file_urls[$name] = $url;

      return true;
    }

    /**
     * Add a locale file from a url to the remote file urls array.
     *
     * @param string $language language for which file to be added
     * @param string $url URL to file
     * @param string|null $name Filename to use in pass archive
     *     (default is equal to $url)
     * @return bool
     */
    public function addLocaleRemoteFile(string $language, string $url, string $name = null): bool
    {
      $name = ($name === null) ? basename($url) : $name;
      $this->remote_file_urls[$language .'.lproj/'. $name] = $url;

      return true;
    }

    /**
     * TODO remove boolean return
     *
     * Create the actual .pkpass file.
     *
     * @param bool $output Whether to output it directly or return the pass
     *     contents as a string.
     *
     * @return bool|string
     */
    public function create(bool $output = false)
    {
        $paths = $this->getTempPaths();

        // Creates and saves the json manifest
        if(!($manifest = $this->createManifest())) {
            $this->clean();

            return false;
        }

        // Create signature
        if($this->createSignature($manifest) == false) {
            $this->clean();

            return false;
        }

        if($this->createZip($manifest) == false) {
            $this->clean();

            return false;
        }

        // Check if pass is created and valid
        if(!file_exists($paths['pkpass']) || filesize($paths['pkpass']) < 1) {
            $this->errorMessage = 'Error while creating pass.pkpass. Check your ZIP extension.';
            $this->clean();

            return false;
        }

        // Get contents of generated file
        $file = file_get_contents($paths['pkpass']);
        $size = filesize($paths['pkpass']);
        $name = basename($paths['pkpass']);

        // Cleanup
        $this->clean();

        // Output pass
        if($output == true) {
            $fileName = $this->getName() ? $this->getName() : $name;
            if(!strstr($fileName, '.')) {
                $fileName .= '.pkpass';
            }
            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.apple.pkpass');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Transfer-Encoding: binary');
            header('Connection: Keep-Alive');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s T'));
            header('Pragma: public');
            if (ob_get_level() > 0)
            {
                ob_end_flush();
            }
            set_time_limit(0);
            echo $file;

            return true;
        }

        return $file;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param $name
     * @return bool
     */
    public function setName($name): bool
    {
        $this->name = $name;

        return true;
    }

    /**
     * Sub-function of create()
     * This function creates the hashes for the files and adds them into a json
     * string.
     * @return string
     */
    protected function createManifest(): string
    {
        // Creates SHA hashes for all files in package
        $this->shas['pass.json'] = sha1($this->json);

        // Creates SHA hashes for string files in each project.
        foreach($this->locales as $language => $strings) {
            $this->shas[$language. '.lproj/pass.strings'] = sha1($strings);
        }

        $has_icon = false;
        foreach($this->files as $name => $path) {
            if(strtolower($name) == 'icon.png') {
                $has_icon = true;
            }
            $this->shas[$name] = sha1(file_get_contents($path));
        }

        foreach($this->remote_file_urls as $name => $url) {
            if(strtolower($name) == 'icon.png') {
                $has_icon = true;
            }
            $this->shas[$name] = sha1(file_get_contents($url));
        }

        if(!$has_icon) {
            $this->errorMessage = 'Missing required icon.png file.';
            $this->clean();

            return false;
        }

        return json_encode((object)$this->shas);
    }

    /**
     * Converts PKCS7 PEM to PKCS7 DER
     * Parameter: string, holding PKCS7 PEM, binary, detached
     * Return: string, PKCS7 DER.
     *
     * @param $signature
     *
     * @return string
     */
    protected function convertPEMtoDER($signature): string
    {
        $begin = 'filename="smime.p7s"';
        $end = '------';
        $signature = substr($signature, strpos($signature, $begin) + strlen($begin));

        $signature = substr($signature, 0, strpos($signature, $end));
        $signature = trim($signature);
        $signature = base64_decode($signature);

        return $signature;
    }

    /**
     * Creates a signature and saves it
     * Parameter: json-string, manifest file
     * Return: boolean, true on success, false on failure.
     *
     * @param $manifest
     *
     * @return bool
     */
    protected function createSignature($manifest): bool
    {
        $paths = $this->getTempPaths();

        file_put_contents($paths['manifest'], $manifest);

        if(!$pkcs12 = file_get_contents($this->certPath)) {
            $this->errorMessage = 'Could not read the certificate';

            return false;
        }

        $certs = [];
        if(!openssl_pkcs12_read($pkcs12, $certs, $this->certPass)) {
            $this->errorMessage = 'Invalid certificate file. Make sure you have a ' .
                'P12 certificate that also contains a private key, and you ' .
                'have specified the correct password!';

            return false;
        }
        $certdata = openssl_x509_read($certs['cert']);
        $privkey = openssl_pkey_get_private($certs['pkey'], $this->certPass);

        $openssl_args = [
            $paths['manifest'],
            $paths['signature'],
            $certdata,
            $privkey,
            [],
            PKCS7_BINARY | PKCS7_DETACHED
        ];

        if(!empty($this->wwdrCertPath)) {
            if(!file_exists($this->wwdrCertPath)) {
                $this->errorMessage = 'WWDR Intermediate Certificate does not exist';

                return false;
            }

            $openssl_args[] = $this->wwdrCertPath;
        }

        call_user_func_array('openssl_pkcs7_sign', $openssl_args);

        $signature = file_get_contents($paths['signature']);
        $signature = $this->convertPEMtoDER($signature);
        file_put_contents($paths['signature'], $signature);

        return true;
    }

    /**
     * Creates .pkpass (zip archive)
     * Parameter: json-string, manifest file
     * Return: boolean, true on succes, false on failure.
     *
     * @param $manifest
     *
     * @return bool
     */
    protected function createZip($manifest): bool
    {
        $paths = $this->getTempPaths();

        // Package file in Zip (as .pkpass)
        $zip = new ZipArchive();
        if(!$zip->open($paths['pkpass'], ZipArchive::CREATE)) {
            $this->errorMessage = 'Could not open ' . basename($paths['pkpass']) . ' with ZipArchive extension.';

            return false;
        }
        $zip->addFile($paths['signature'], 'signature');
        $zip->addFromString('manifest.json', $manifest);
        $zip->addFromString('pass.json', $this->json);

        // Add translation dictionary
        foreach($this->locales as $language => $strings) {
            if(!$zip->addEmptyDir($language . '.lproj')) {
                $this->errorMessage = 'Could not create ' . $language . '.lproj folder in zip archive.';

                return false;
            }
            $zip->addFromString($language. '.lproj/pass.strings', $strings);
        }

        foreach($this->files as $name => $path) {
            $zip->addFile($path, $name);
        }

        foreach($this->remote_file_urls as $name => $url) {
            $download_file = file_get_contents($url);
            $zip->addFromString($name, $download_file);
        }
        $zip->close();

        return true;
    }

    /**
     * Declares all paths used for temporary files.
     * @return string[]
     */
    protected function getTempPaths(): array
    {
        // Declare base paths
        $paths = [
            'pkpass' => 'pass.pkpass',
            'signature' => 'signature',
            'manifest' => 'manifest.json',
        ];

        // If trailing slash is missing, add it
        if(substr($this->tempPath, -1) != '/') {
            $this->tempPath = $this->tempPath . '/';
        }

        // Generate a unique sub-folder in the tempPath to support generating more
        // passes at the same time without erasing/overwriting each others files
        if(empty($this->uniqid)) {
            $this->uniqid = uniqid('PKPass', true);
        }

        if(!is_dir($this->tempPath . $this->uniqid)) {
            mkdir($this->tempPath . $this->uniqid);
        }

        // Add temp folder path
        foreach($paths as $pathName => $path) {
            $paths[$pathName] = $this->tempPath . $this->uniqid . '/' . $path;
        }

        return $paths;
    }

    /**
     * Removes all temporary files.
     * @return bool
     */
    protected function clean(): bool
    {
        $paths = $this->getTempPaths();

        foreach($paths as $path) {
            if(file_exists($path)) {
                unlink($path);
            }
        }

        // Remove our unique temporary folder
        if(is_dir($this->tempPath . $this->uniqid)) {
            rmdir($this->tempPath . $this->uniqid);
        }

        return true;
    }

    /**
     * @var string[]
     */
    protected static array $escapeChars = [
        "\n" => "\\n",
        "\r" => "\\r",
        "\"" => "\\\"",
        "\\" => "\\\\"
    ];

    /**
     * Escapes strings for use in locale files
     * @param string $string
     * @return string
     */
    protected function escapeLocaleString(string $string): string
    {
        return strtr($string, self::$escapeChars);
    }
}
