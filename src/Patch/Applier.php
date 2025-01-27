<?php
/**
 * Implementation of the a patch mechanism for unified diff file.
 * This method allow the validation of the whole patch before modifying any file.
 *
 * @author Francois Mazerolle <fmaz008@gmail.com>
 */
namespace Vaimo\UnifiedDiffPatcher\Patch;

class Applier
{
    private $debug = false;
    private $arrError = array();
    private $patch = false;

    protected $patchHandle;
    protected $patchLine = 0;

    protected $dstHandle;
    protected $dstPath;
    protected $dstLine = 0;

    protected $srcHandle;
    protected $srcPath;
    protected $srcLine = 0;

    public function __construct()
    {
    }

    protected function debug($txt)
    {
        if ($this->debug) {
            echo $txt . PHP_EOL;
        }
    }

    protected function addError($txt)
    {
        $this->arrError[] = $txt;
    }

    public function getError()
    {
        return $this->arrError;
    }

    public function hasError()
    {
        return !empty($this->arrError);
    }

    public function processPatch($patchFile, $patchLevel = '-1', $debug = false)
    {
        $this->patch = true;
        $this->debug = (bool)$debug;

        $this->openPatch($patchFile);
        $this->processFiles($patchLevel);

        return !$this->hasError();
    }

    public function validatePatch($patchFile, $patchLevel = '-1', $debug = false)
    {
        $this->patch = false;
        $this->debug = (bool)$debug;

        $this->debug('Validating patch (read-only mode)');

        $this->openPatch($patchFile);
        $this->processFiles($patchLevel);

        return !$this->hasError();
    }

    protected function processFiles($patchLevel)
    {
        $line = fgets($this->patchHandle);

        $this->patchLine++;
        $this->srcHandle = null;

        do {
            if ($this->srcHandle) {
                $this->processHunks($line);

                $this->copyOriginalLines($this->srcLine+1, false);

                if ($this->patch) {
                    unlink($this->srcPath);
                }

                $this->srcHandle = null;
            }

            if (strpos($line, '+++ ') === 0) {
                $this->srcPath = $this->extractFileName($line, $patchLevel);

                $this->debug(sprintf('Patching %s...', $this->srcPath));

                if ($this->patch) {
                    $this->dstPath = $this->srcPath;
                    $this->srcPath .= '.orig';
                    rename($this->dstPath, $this->srcPath);
                }

                $this->srcHandle = fopen($this->srcPath, 'r');
                $this->srcLine = 0;

                if (!$this->srcHandle) {
                    $this->addError(sprintf('File %s not found.', $this->srcHandle));
                    $this->srcHandle = null;
                }

                if ($this->patch) {
                    $this->dstHandle = fopen($this->dstPath, 'w');

                    if (!$this->dstHandle) {
                        $this->addError(sprintf('File not found: %s', $this->dstHandle));
                        $this->srcHandle = null;
                    }
                }
            }

            $line = fgets($this->patchHandle);
            $this->patchLine++;
        } while (false !== $line);
    }

    protected function processHunks($line)
    {
        $arrHunk = array(
            'no' => 0,
            'srcBegLine' => null,
            'dstBegLine' => null,
            'srcLastLine' => 1,
            'dstLastLine' => 1,
        );

        $hunkSkip = false;

        do {
            $cmd = $line[0];

            if (($cmd === 'O' || $cmd === 'd' || $cmd === '@') && !$hunkSkip && $arrHunk['no'] !== 0) {
                $from = $arrHunk['srcBegLine'];
                $to = $arrHunk['srcBegLine'] + $arrHunk['srcLastLine'] - 1;

                $this->debug(sprintf("\t\tModified lines %u to %u.", $from, $to));
            }

            if ($cmd === 'O' || $cmd === 'd') {
                return;
            }

            if ($cmd === '@') {
                $hunkSkip = false;

                sscanf(
                    $line,
                    '@@ -%d,%d +%d,%d',
                    $arrHunk['srcBegLine'],
                    $arrHunk['srcLastLine'],
                    $arrHunk['dstBegLine'],
                    $arrHunk['dstLastLine']
                );

                sscanf(
                    $line,
                    '@@ -%d +%d,%d',
                    $arrHunk['srcBegLine'],
                    $arrHunk['dstBegLine'],
                    $arrHunk['dstLastLine']
                );

                $arrHunk['no']++;

                $this->debug(sprintf("\tChecking hunk #%u", $arrHunk['no']));

                $this->copyOriginalLines($this->srcLine + 1, $arrHunk['srcBegLine'] - 1);
            } elseif ($cmd === '+' || $cmd === '-' || $cmd === ' ') {
                if (!$hunkSkip) {
                    $ret = $this->processInstruction($line);

                    if (!$ret) {
                        $this->debug(sprintf("\t\tHunk FAILED."));
                        $hunkSkip = true;
                    }
                }
            } else {
                $this->addError(sprintf('Line #%u of the patch file seems invalid.', $this->patchLine));
            }

            $line = fgets($this->patchHandle);
            $this->patchLine++;
        } while (false !== $line);
    }

    protected function processInstruction($line)
    {
        $cmd = $line[0];
        $code = substr($line, 1);

        if ($cmd !== '+') {
	        // Ignore newline at the end for matching purposes
	        $readcode = rtrim( $code );
	        $readline = rtrim( fgets( $this->srcHandle ) );

	        $diff = strcmp(
		        $readcode,
		        $readline
	        );

            if ($diff !== 0) {
                $message = sprintf(
                    'Line #%u of the patch file could not be matched with line #%u of %s.',
                    $this->patchLine,
                    $this->srcLine,
                    $this->srcPath
                );

                $this->addError($message);

                return false;
            }

            $this->srcLine++;
        }

        if ($cmd !== '-' && $this->patch) {
            if (!fwrite($this->dstHandle, $code)) {
                throw new Exception('Error writing to new file');
            }

            $this->dstLine++;
        }

        return true;
    }

    protected function openPatch($filePath)
    {
        $this->debug(sprintf('Opening %s', $filePath));

        $this->patchHandle = fopen($filePath, 'r');
        $this->patchLine = 0;

        if (!$this->patchHandle) {
            throw new Exception(sprintf('Could not open file %s.', $filePath));
        }

        return true;
    }

    protected function extractFileName($line, $patchLevel)
    {
        $line = preg_replace('|^[+-]{3}\s|', '', trim($line));

        while ($patchLevel--) {
            $cut = strstr($line, '/');

            if (!$cut) {
                break;
            }

            $line = ltrim($cut, '/');
        }

        return $line;
    }

    protected function copyOriginalLines($from, $to)
    {
        if ($to === false) {
            $to = PHP_INT_MAX;
        }

        if ($from < 0 || $to <= 0) {
            return false;
        }

        for ($i = $from; $to >= $i; $i++) {
            $line = fgets($this->srcHandle);

            if (!$line) {
                break;
            }

            if ($this->patch) {
                if (!fwrite($this->dstHandle, $line)) {
                    throw new Exception('Error writing to new file');
                }
                $this->dstLine++;
            }
        }

        if ($from !== $i) {
            $message = sprintf("\t\tCopied unmodified lines %u to %u.", $from, $i - 1);

            $this->debug($message);
        }

        $this->srcLine += $to - $from + 1;

        return true;
    }
}
