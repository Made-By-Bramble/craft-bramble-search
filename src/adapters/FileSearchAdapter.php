<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\helpers\FileHelper;
use yii\base\Exception;

/**
 * File Search Adapter
 *
 * Implements the search adapter using a custom binary file format as the storage backend.
 * Provides persistent storage with no external dependencies.
 * Uses file locking for concurrent access and a custom binary format for efficiency.
 */
class FileSearchAdapter extends BaseSearchAdapter
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * Base directory for all search files
     */
    protected string $baseDir;

    /**
     * Directory for document data
     */
    protected string $docsDir;

    /**
     * Directory for term data
     */
    protected string $termsDir;

    /**
     * Directory for metadata
     */
    protected string $metaDir;

    /**
     * Directory for title terms
     */
    protected string $titlesDir;

    /**
     * File lock timeout in seconds
     */
    protected int $lockTimeout = 10;

    /**
     * File format version
     */
    protected int $fileFormatVersion = 1;

    /**
     * Magic bytes for file format identification
     */
    protected string $magicBytes = 'BRMS';

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the file storage
     */
    public function init(): void
    {
        parent::init();

        // Set up base directory
        $storagePath = Craft::getAlias('@storage');
        $this->baseDir = $storagePath . '/runtime/bramble-search';

        // Set up subdirectories
        $this->docsDir = $this->baseDir . '/docs';
        $this->termsDir = $this->baseDir . '/terms';
        $this->metaDir = $this->baseDir . '/meta';
        $this->titlesDir = $this->baseDir . '/titles';

        // Ensure directories exist
        $this->ensureDirectoriesExist();
    }

    /**
     * Ensure all required directories exist
     */
    protected function ensureDirectoriesExist(): void
    {
        try {
            FileHelper::createDirectory($this->baseDir);
            FileHelper::createDirectory($this->docsDir);
            FileHelper::createDirectory($this->termsDir);
            FileHelper::createDirectory($this->metaDir);
            FileHelper::createDirectory($this->titlesDir);
        } catch (Exception $e) {
            Craft::error('Failed to create search directories: ' . $e->getMessage(), 'bramble-search');
            throw new \RuntimeException('Failed to create search directories: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // FILE OPERATIONS
    // =========================================================================

    /**
     * Get a file handle with appropriate locking
     *
     * @param string $path File path
     * @param string $mode File open mode
     * @param bool $lock Whether to lock the file
     * @return resource|false File handle or false on failure
     */
    protected function getFileHandle(string $path, string $mode, bool $lock = true)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            return false;
        }

        if ($lock) {
            $lockType = str_contains($mode, 'r') && !str_contains($mode, '+')
                ? LOCK_SH  // Shared lock for reading
                : LOCK_EX; // Exclusive lock for writing

            $startTime = microtime(true);
            $locked = false;

            // Try to acquire lock with timeout
            while (microtime(true) - $startTime < $this->lockTimeout) {
                if (flock($handle, $lockType | LOCK_NB, $wouldBlock)) {
                    $locked = true;
                    break;
                }

                if (!$wouldBlock) {
                    break; // If lock fails for reasons other than blocking, exit loop
                }

                // Wait a bit before retrying
                usleep(100000); // 100ms
            }

            if (!$locked) {
                fclose($handle);
                return false;
            }
        }

        return $handle;
    }

    /**
     * Release a file handle and lock
     *
     * @param resource $handle File handle
     * @return bool Whether the operation was successful
     */
    protected function releaseFileHandle($handle): bool
    {
        if (!is_resource($handle)) {
            return false;
        }

        // Release lock
        flock($handle, LOCK_UN);

        // Close handle
        return fclose($handle);
    }

    /**
     * Write data to a file with proper locking
     *
     * @param string $path File path
     * @param string $data Binary data to write
     * @return bool Whether the operation was successful
     */
    protected function writeFile(string $path, string $data): bool
    {
        $handle = $this->getFileHandle($path, 'wb');

        if ($handle === false) {
            Craft::error("Failed to open file for writing: $path", 'bramble-search');
            return false;
        }

        // Write header
        $header = $this->createFileHeader();
        fwrite($handle, $header);

        // Write data
        $bytesWritten = fwrite($handle, $data);
        $success = ($bytesWritten !== false);

        $this->releaseFileHandle($handle);

        return $success;
    }

    /**
     * Read data from a file with proper locking
     *
     * @param string $path File path
     * @return string|false The file contents or false on failure
     */
    protected function readFile(string $path)
    {
        if (!file_exists($path)) {
            return false;
        }

        $handle = $this->getFileHandle($path, 'rb');

        if ($handle === false) {
            Craft::error("Failed to open file for reading: $path", 'bramble-search');
            return false;
        }

        // Skip header
        $headerSize = strlen($this->createFileHeader());
        fseek($handle, $headerSize);

        // Read data
        $data = stream_get_contents($handle);

        $this->releaseFileHandle($handle);

        return $data;
    }

    /**
     * Create a file header
     *
     * @return string Binary header data
     */
    protected function createFileHeader(): string
    {
        // Format: Magic bytes (4) + Version (4) + Reserved (8)
        $header = $this->magicBytes;
        $header .= pack('V', $this->fileFormatVersion);
        $header .= str_repeat("\0", 8); // Reserved for future use

        return $header;
    }

    /**
     * Delete a file
     *
     * @param string $path File path
     * @return bool Whether the operation was successful
     */
    protected function deleteFile(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        return @unlink($path);
    }

    /**
     * Encode data to binary format
     *
     * @param mixed $data Data to encode
     * @return string Binary data
     */
    protected function encodeData($data): string
    {
        // For arrays and objects, use a custom binary format
        if (is_array($data) || is_object($data)) {
            return $this->encodeBinary($data);
        }

        // For scalar values, use simple serialization
        return serialize($data);
    }

    /**
     * Decode data from binary format
     *
     * @param string $data Binary data
     * @return mixed Decoded data
     */
    protected function decodeData(string $data)
    {
        // Check if this is our custom binary format
        if (strlen($data) > 0 && ord($data[0]) === 0xB1) {
            return $this->decodeBinary($data);
        }

        // Otherwise, try standard serialization
        try {
            return unserialize($data);
        } catch (\Exception $e) {
            Craft::error('Failed to decode data: ' . $e->getMessage(), 'bramble-search');
            return false;
        }
    }

    /**
     * Encode data to custom binary format
     *
     * Format:
     * - Byte 0: Format marker (0xB1)
     * - Byte 1: Data type (1=array, 2=object)
     * - Bytes 2-5: Item count (uint32)
     * - Remaining bytes: Key-value pairs
     *   - For each pair:
     *     - 2 bytes: Key length (uint16)
     *     - Key bytes
     *     - 4 bytes: Value length (uint32)
     *     - Value bytes (serialized)
     *
     * @param mixed $data Data to encode
     * @return string Binary data
     */
    protected function encodeBinary($data): string
    {
        $isObject = is_object($data);
        $data = (array)$data;
        $count = count($data);

        // Start with format marker and type
        $binary = chr(0xB1) . chr($isObject ? 2 : 1);

        // Add item count
        $binary .= pack('V', $count);

        // Add each key-value pair
        foreach ($data as $key => $value) {
            // Convert key to string
            $key = (string)$key;
            $keyLength = strlen($key);

            // Serialize value
            $serializedValue = serialize($value);
            $valueLength = strlen($serializedValue);

            // Add key length (2 bytes)
            $binary .= pack('v', $keyLength);

            // Add key
            $binary .= $key;

            // Add value length (4 bytes)
            $binary .= pack('V', $valueLength);

            // Add value
            $binary .= $serializedValue;
        }

        return $binary;
    }

    /**
     * Decode data from custom binary format
     *
     * @param string $data Binary data
     * @return mixed Decoded data
     */
    protected function decodeBinary(string $data)
    {
        // Check minimum length and format marker
        if (strlen($data) < 7 || ord($data[0]) !== 0xB1) {
            return false;
        }

        // Get type
        $type = ord($data[1]);
        $isObject = ($type === 2);

        // Get item count
        $count = unpack('V', substr($data, 2, 4))[1];

        // Initialize result
        $result = [];

        // Current position in binary data
        $pos = 6;

        // Read each key-value pair
        for ($i = 0; $i < $count; $i++) {
            // Check if we have enough data left
            if ($pos + 2 > strlen($data)) {
                break;
            }

            // Get key length
            $keyLength = unpack('v', substr($data, $pos, 2))[1];
            $pos += 2;

            // Check if we have enough data left
            if ($pos + $keyLength > strlen($data)) {
                break;
            }

            // Get key
            $key = substr($data, $pos, $keyLength);
            $pos += $keyLength;

            // Check if we have enough data left
            if ($pos + 4 > strlen($data)) {
                break;
            }

            // Get value length
            $valueLength = unpack('V', substr($data, $pos, 4))[1];
            $pos += 4;

            // Check if we have enough data left
            if ($pos + $valueLength > strlen($data)) {
                break;
            }

            // Get value
            $serializedValue = substr($data, $pos, $valueLength);
            $pos += $valueLength;

            // Unserialize value
            try {
                $value = unserialize($serializedValue);
                $result[$key] = $value;
            } catch (\Exception $e) {
                Craft::error('Failed to unserialize value: ' . $e->getMessage(), 'bramble-search');
            }
        }

        // Convert to object if needed
        if ($isObject) {
            $result = (object)$result;
        }

        return $result;
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Get all terms for a document from file storage
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return array The terms and their frequencies
     */
    protected function getDocumentTerms(int $siteId, int $elementId): array
    {
        $docPath = $this->docsDir . "/{$siteId}_{$elementId}.dat";
        $data = $this->readFile($docPath);

        if ($data === false) {
            return [];
        }

        $terms = $this->decodeData($data);

        // Handle decode failure
        if ($terms === false || !is_array($terms)) {
            return [];
        }

        // Remove the _length key which isn't a term
        if (isset($terms['_length'])) {
            unset($terms['_length']);
        }

        return $terms;
    }

    /**
     * Remove a term-document association
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $termPath = $this->termsDir . "/{$term}.dat";
        $data = $this->readFile($termPath);

        if ($data === false) {
            return;
        }

        $docs = $this->decodeData($data);

        // Handle decode failure
        if ($docs === false || !is_array($docs)) {
            return;
        }

        $docId = "{$siteId}:{$elementId}";

        // Remove the document from the term's document list
        if (isset($docs[$docId])) {
            unset($docs[$docId]);

            // If there are still documents for this term, update the file
            if (!empty($docs)) {
                $this->writeFile($termPath, $this->encodeData($docs));
            } else {
                // Otherwise, delete the term file
                $this->deleteFile($termPath);
            }
        }
    }

    /**
     * Delete a document from the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    protected function deleteDocument(int $siteId, int $elementId): void
    {
        $docPath = $this->docsDir . "/{$siteId}_{$elementId}.dat";
        $this->deleteFile($docPath);
    }

    /**
     * Store a document in file storage
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $termFreqs The terms and their frequencies
     * @param int $docLen The document length
     * @return void
     */
    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $docPath = $this->docsDir . "/{$siteId}_{$elementId}.dat";

        // Add document length as a special term
        $data = $termFreqs;
        $data['_length'] = $docLen;

        $this->writeFile($docPath, $this->encodeData($data));
    }

    /**
     * Store a term-document association
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param int $freq The term frequency
     * @return void
     */
    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $termPath = $this->termsDir . "/{$term}.dat";
        $docId = "{$siteId}:{$elementId}";

        // Get existing term data
        $data = $this->readFile($termPath);
        $docs = [];

        if ($data !== false) {
            $docs = $this->decodeData($data);

            // Handle decode failure
            if ($docs === false) {
                $docs = [];
            }
        }

        // Add or update document frequency
        $docs[$docId] = $freq;

        // Write updated term data
        $this->writeFile($termPath, $this->encodeData($docs));

        // Update the all terms index
        $this->addTermToIndex($term);
    }

    /**
     * Add a document to the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    protected function addDocumentToIndex(int $siteId, int $elementId): void
    {
        $metaPath = $this->metaDir . "/docs.dat";
        $docId = "{$siteId}:{$elementId}";

        // Get existing docs
        $data = $this->readFile($metaPath);
        $docs = [];

        if ($data !== false) {
            $docs = $this->decodeData($data);

            // Handle decode failure
            if ($docs === false) {
                $docs = [];
            }
        }

        // Add document if not already in the index
        if (!in_array($docId, $docs)) {
            $docs[] = $docId;
            $this->writeFile($metaPath, $this->encodeData($docs));
        }
    }

    // =========================================================================
    // METADATA OPERATIONS
    // =========================================================================

    /**
     * Update the total document count
     *
     * @return void
     */
    protected function updateTotalDocCount(): void
    {
        $metaPath = $this->metaDir . "/docs.dat";
        $data = $this->readFile($metaPath);

        if ($data === false) {
            // No documents yet
            $count = 0;
        } else {
            $docs = $this->decodeData($data);

            // Handle decode failure
            if ($docs === false || !is_array($docs)) {
                $count = 0;
            } else {
                $count = count($docs);
            }
        }

        $countPath = $this->metaDir . "/count.dat";
        $this->writeFile($countPath, $this->encodeData($count));
    }

    /**
     * Update the total length
     *
     * @param int $docLen The document length to add
     * @return void
     */
    protected function updateTotalLength(int $docLen): void
    {
        $lengthPath = $this->metaDir . "/length.dat";
        $data = $this->readFile($lengthPath);

        if ($data === false) {
            // No total length yet
            $totalLength = $docLen;
        } else {
            $totalLength = $this->decodeData($data);

            // Handle decode failure
            if ($totalLength === false || !is_numeric($totalLength)) {
                $totalLength = $docLen;
            } else {
                $totalLength += $docLen;
            }
        }

        $this->writeFile($lengthPath, $this->encodeData($totalLength));
    }

    /**
     * Get the total document count
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $countPath = $this->metaDir . "/count.dat";
        $data = $this->readFile($countPath);

        if ($data === false) {
            return 0;
        }

        $count = $this->decodeData($data);

        // Handle decode failure
        if ($count === false || !is_numeric($count)) {
            return 0;
        }

        return (int)$count;
    }

    /**
     * Get the total length
     *
     * @return int The total length
     */
    protected function getTotalLength(): int
    {
        $lengthPath = $this->metaDir . "/length.dat";
        $data = $this->readFile($lengthPath);

        if ($data === false) {
            return 0;
        }

        $length = $this->decodeData($data);

        // Handle decode failure
        if ($length === false || !is_numeric($length)) {
            return 0;
        }

        return (int)$length;
    }

    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a term
     *
     * @param string $term The term
     * @return array The documents and their frequencies
     */
    protected function getTermDocuments(string $term): array
    {
        $termPath = $this->termsDir . "/{$term}.dat";
        $data = $this->readFile($termPath);

        if ($data === false) {
            return [];
        }

        $docs = $this->decodeData($data);

        // Handle decode failure
        if ($docs === false || !is_array($docs)) {
            return [];
        }

        return $docs;
    }

    /**
     * Get the document length
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return int The document length
     */
    protected function getDocumentLength(string $docId): int
    {
        [$siteId, $elementId] = explode(':', $docId);
        $docPath = $this->docsDir . "/{$siteId}_{$elementId}.dat";
        $data = $this->readFile($docPath);

        if ($data === false) {
            return 0;
        }

        $doc = $this->decodeData($data);

        // Handle decode failure
        if ($doc === false || !is_array($doc)) {
            return 0;
        }

        return (int)($doc['_length'] ?? 0);
    }

    /**
     * Get all terms in the index
     *
     * @return array All terms
     */
    protected function getAllTerms(): array
    {
        $termsPath = $this->metaDir . "/terms.dat";
        $data = $this->readFile($termsPath);

        if ($data === false) {
            return [];
        }

        $terms = $this->decodeData($data);

        // Handle decode failure
        if ($terms === false || !is_array($terms)) {
            return [];
        }

        return $terms;
    }

    /**
     * Add a term to the index
     *
     * @param string $term The term to add
     * @return void
     */
    protected function addTermToIndex(string $term): void
    {
        $termsPath = $this->metaDir . "/terms.dat";
        $data = $this->readFile($termsPath);
        $terms = [];

        if ($data !== false) {
            $terms = $this->decodeData($data);

            // Handle decode failure
            if ($terms === false) {
                $terms = [];
            }
        }

        // Add term if not already in the index
        if (!in_array($term, $terms)) {
            $terms[] = $term;
            $this->writeFile($termsPath, $this->encodeData($terms));
        }
    }

    /**
     * Remove a term from the index
     *
     * @param string $term The term to remove
     * @return void
     */
    protected function removeTermFromIndex(string $term): void
    {
        $termsPath = $this->metaDir . "/terms.dat";
        $data = $this->readFile($termsPath);

        if ($data === false) {
            return;
        }

        $terms = $this->decodeData($data);

        // Handle decode failure
        if ($terms === false || !is_array($terms)) {
            return;
        }

        // Remove term from the index
        $key = array_search($term, $terms);
        if ($key !== false) {
            unset($terms[$key]);
            $terms = array_values($terms); // Reindex array
            $this->writeFile($termsPath, $this->encodeData($terms));
        }

        // Delete the term file
        $termPath = $this->termsDir . "/{$term}.dat";
        $this->deleteFile($termPath);
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * Store title terms for a document
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $titleTerms The title terms
     * @return void
     */
    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $titlePath = $this->titlesDir . "/{$siteId}_{$elementId}.dat";
        $this->writeFile($titlePath, $this->encodeData($titleTerms));
    }

    /**
     * Get title terms for a document
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return array The title terms
     */
    protected function getTitleTerms(string $docId): array
    {
        [$siteId, $elementId] = explode(':', $docId);
        $titlePath = $this->titlesDir . "/{$siteId}_{$elementId}.dat";
        $data = $this->readFile($titlePath);

        if ($data === false) {
            return [];
        }

        $terms = $this->decodeData($data);

        // Handle decode failure
        if ($terms === false || !is_array($terms)) {
            return [];
        }

        return $terms;
    }

    /**
     * Delete title terms for a document
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titlePath = $this->titlesDir . "/{$siteId}_{$elementId}.dat";
        $this->deleteFile($titlePath);
    }

    // =========================================================================
    // SITE OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a specific site
     *
     * @param int $siteId The site ID
     * @return array The document IDs
     */
    protected function getSiteDocuments(int $siteId): array
    {
        $metaPath = $this->metaDir . "/docs.dat";
        $data = $this->readFile($metaPath);

        if ($data === false) {
            return [];
        }

        $allDocs = $this->decodeData($data);

        // Handle decode failure
        if ($allDocs === false || !is_array($allDocs)) {
            return [];
        }

        // Filter documents by site ID
        $siteDocs = [];
        foreach ($allDocs as $docId) {
            if (str_starts_with($docId, "$siteId:")) {
                $siteDocs[] = $docId;
            }
        }

        return $siteDocs;
    }

    /**
     * Remove a document from the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
        $metaPath = $this->metaDir . "/docs.dat";
        $data = $this->readFile($metaPath);

        if ($data === false) {
            return;
        }

        $docs = $this->decodeData($data);

        // Handle decode failure
        if ($docs === false || !is_array($docs)) {
            return;
        }

        $docId = "{$siteId}:{$elementId}";

        // Remove document from the index
        $key = array_search($docId, $docs);
        if ($key !== false) {
            unset($docs[$key]);
            $docs = array_values($docs); // Reindex array
            $this->writeFile($metaPath, $this->encodeData($docs));
        }
    }

    /**
     * Reset the total length counter
     *
     * @return void
     */
    protected function resetTotalLength(): void
    {
        $lengthPath = $this->metaDir . "/length.dat";
        $this->writeFile($lengthPath, $this->encodeData(0));
    }
}
