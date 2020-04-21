<?php


namespace sinri\ark\ftp;

use Exception;

class ArkFTP
{

    /**
     * @var resource
     */
    private $connectionID;

    /**
     * @param string $username
     * @param string $password
     * @param string $server
     * @param int $port
     * @param int $timeout
     * @param bool $isPassive
     * @return ArkFTP
     * @throws Exception
     */
    public static function createFTPConnection($username, $password, $server, $port = 21, $timeout = 90, $isPassive = true)
    {
        return (new ArkFTP())
            ->connect($server, $port, $timeout)
            ->loginWithAuthPair($username, $password)
            ->setPassiveMode($isPassive);
    }

    /**
     * @param string $server
     * @param int $port
     * @param int $timeout
     * @return $this|bool
     * @throws Exception
     */
    public function connect($server, $port = 21, $timeout = 90)
    {
        $this->connectionID = ftp_connect(self::filterServerChars($server), $port, $timeout);
        if ($this->connectionID === false) {
            throw new Exception("Cannot connect to FTP Server " . $server . ':' . $port);
        }
        return $this;
    }

    /**
     * @param bool $isPassive
     * @return $this
     * @throws Exception
     */
    public function setPassiveMode($isPassive)
    {
        $done = ftp_pasv($this->connectionID, $isPassive);
        if (!$done) {
            throw new Exception("Cannot set FTP Passive Mode as " . json_encode($isPassive));
        }
        return $this;
    }

    /**
     * @param string $path
     * @return $this
     * @throws Exception
     */
    public function chdir($path = '')
    {
        if ($path == '') {
            throw new Exception("Illegal Path");
        }
        $this->verifyConnection();

        $result = ftp_chdir($this->connectionID, $path);
        if ($result === false) {
            throw new Exception("Cannot change directory to " . json_encode($path));
        }

        return $this;
    }

    /**
     * @param string $path
     * @param null $permissions
     * @return $this
     * @throws Exception
     */
    public function mkdir($path = '', $permissions = null)
    {
        if ($path == '') {
            throw new Exception("Illegal Path");
        }
        $this->verifyConnection();

        $result = ftp_mkdir($this->connectionID, $path);

        if ($result === false) {
            throw new Exception("Cannot make directory as " . json_encode($path));
        }

        if (!is_null($permissions)) {
            $this->chmod($path, (int)$permissions);
        }

        return $this;
    }

    /**
     * @param string $localPath
     * @param string $remotePath
     * @param null|int $mode null for `auto`, FTP_ASCII for `ascii` , FTP_BINARY for `binary`
     * @param null|int $permissions 0777 or so
     * @return ArkFTP
     * @throws Exception
     */
    public function upload($localPath, $remotePath, $mode = null, $permissions = NULL)
    {
        $this->verifyConnection();
        if (!file_exists($localPath)) {
            throw new Exception("Local file is missing");
        }

        // check upload mode
        if ($mode === null) {
            $mode = self::getFTPTransferModeForExtension(self::getFileExtension($localPath));
        }
        //$mode = ($mode == 'ascii') ? FTP_ASCII : FTP_BINARY;

        $result = ftp_put($this->connectionID, $remotePath, $localPath, $mode);
        if ($result === false) {
            throw new Exception("Failed to upload file " . json_encode($localPath) . " to FTP " . json_encode($remotePath));
        }

        if (!is_null($permissions)) {
            $this->chmod($remotePath, (int)$permissions);
        }

        return $this;
    }

    /**
     * @param string $remotePath
     * @param string $localPath
     * @param null|int $mode null for `auto`, FTP_ASCII for `ascii` , FTP_BINARY for `binary`
     * @return $this
     * @throws Exception
     */
    public function download($remotePath, $localPath, $mode = null)
    {
        $this->verifyConnection();

        if ($mode === null) {
            $mode = self::getFTPTransferModeForExtension(self::getFileExtension($remotePath));
        }
//        $mode = ($mode == 'ascii') ? FTP_ASCII : FTP_BINARY;

        $result = ftp_get($this->connectionID, $localPath, $remotePath, $mode);
        if ($result === FALSE) {
            throw new Exception("Failed to download remote file " . json_encode($remotePath) . ' to local ' . json_encode($localPath));
        }

        return $this;
    }

    /**
     * @param string $oldName
     * @param string $newName
     * @return $this
     * @throws Exception
     */
    public function rename($oldName, $newName)
    {
        $this->verifyConnection();

        $result = ftp_rename($this->connectionID, $oldName, $newName);
        if ($result === FALSE) {
            throw new Exception("Cannot execute rename from " . json_encode($oldName) . ' to ' . json_encode($newName));
        }

        return $this;
    }

    /**
     * @param string $file
     * @return $this
     * @throws Exception
     */
    public function deleteFile($file)
    {
        $this->verifyConnection();

        $result = ftp_delete($this->connectionID, $file);
        if ($result === FALSE) {
            throw new Exception("Failed to delete file " . json_encode($file));
        }

        return $this;
    }

    /**
     * @param string $path
     * @return $this
     * @throws Exception
     */
    public function deleteDirectory($path)
    {
        $this->verifyConnection();

        // Add `\` before `/` in $path
        $path = preg_replace("/(.+?)\/*$/", "\\1/", $path);


        $fileList = $this->getFileListInDirectory($path);
        if (!empty($fileList)) {
            foreach ($fileList as $item) {
                // Try to treat it as file, if error, try to treat it as directory
                try {
                    $this->deleteFile($item);
                } catch (Exception $e) {
                    $this->deleteDirectory($item);
                }
            }
        }

        $result = ftp_rmdir($this->connectionID, $path);
        if ($result === FALSE) {
            throw new Exception("Failed to delete directory " . json_encode($path));
        }

        return $this;
    }

    /**
     * @param string $path
     * @param int $perm
     * @return $this
     * @throws Exception
     */
    public function chmod($path, $perm)
    {
        $this->verifyConnection();

        // This function is provided after PHP 5
        if (!function_exists('ftp_chmod')) {
            throw new Exception("Function ftp_chmod not defined");
        }

        $result = ftp_chmod($this->connectionID, $perm, $path);
        if ($result === false) {
            throw new Exception("Cannot execute chmod on " . json_encode($path) . ' as ' . json_encode($perm));
        }

        return $this;
    }

    /**
     * NOTE: the second parameter `path`:
     * The directory to be listed. This parameter can also include arguments, eg.
     * ftp_nlist($conn_id, "-la /your/dir");
     * Note that this parameter isn't escaped so there may be some issues with
     * filenames containing spaces and other characters.
     *
     * @param string $path
     * @return array
     * @throws Exception
     */
    public function getFileListInDirectory($path = '.')
    {
        $this->verifyConnection();
        return ftp_nlist($this->connectionID, $path);
    }

    /**
     * @return bool
     */
    public function close()
    {
        try {
            $this->verifyConnection();
            return ftp_close($this->connectionID);
        } catch (Exception $e) {
            return true;
        }
    }

    /**
     * Remove the header protocol schema component
     * @param string $server
     * @return string|string[]|null
     */
    public static function filterServerChars($server)
    {
        return preg_replace('|.+?://|', '', $server);
    }

    /**
     * @param string $username
     * @param string $password
     * @return $this
     * @throws Exception
     */
    public function loginWithAuthPair($username, $password)
    {
        $passed = ftp_login($this->connectionID, $username, $password);
        if (!$passed) {
            throw new Exception("Cannot login");
        }
        return $this;
    }

    /**
     * @return $this
     * @throws Exception
     */
    private function verifyConnection()
    {
        if (!is_resource($this->connectionID)) {
            throw new Exception("No valid connection here");
        }
        return $this;
    }

    /**
     * @param $filename
     * @return mixed|string
     */
    private static function getFileExtension($filename)
    {
        if (FALSE === strpos($filename, '.')) {
            return 'txt';
        }

        $extArray = explode('.', $filename);
        return end($extArray);
    }

    /**
     * @param string $ext
     * @return int FTP_ASCII for `ascii` , FTP_BINARY for `binary`
     */
    private static function getFTPTransferModeForExtension($ext)
    {
        $text_type = array(
            'txt',
            'text',
            'php',
            'phps',
            'php4',
            'js',
            'css',
            'htm',
            'html',
            'phtml',
            'shtml',
            'log',
            'xml'
        );

        return (in_array($ext, $text_type)) ? FTP_ASCII : FTP_BINARY;
    }
}
