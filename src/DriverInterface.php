<?php


namespace As247\Flysystem\GoogleDrive;


use As247\Flysystem\GoogleDrive\Exceptions\GoogleDriveException;
use League\Flysystem\Config;

interface DriverInterface
{
	/**
	 * @throws GoogleDriveException
	 */
	public function fileExists(string $path): bool;

	/**
	 * @throws GoogleDriveException
	 * @throws GoogleDriveException
	 */
	public function write(string $path, string $contents, Config $config): void;

	/**
	 * @param resource $contents
	 * @throws GoogleDriveException
	 */
	public function writeStream(string $path, $contents, Config $config): void;

	/**
	 * @throws GoogleDriveException
	 */
	public function read(string $path): string;

	/**
	 * @return resource
	 * @throws GoogleDriveException
	 */
	public function readStream(string $path);

	/**
	 * @throws GoogleDriveException
	 */
	public function delete(string $path): void;

	/**
	 * @throws GoogleDriveException
	 */
	public function deleteDirectory(string $path): void;

	/**
	 * @throws GoogleDriveException
	 */
	public function createDirectory(string $path, Config $config): void;

	/**
	 * @param mixed $visibility
	 * @throws GoogleDriveException
	 */
	public function setVisibility(string $path, $visibility): void;

	/**
	 * @throws GoogleDriveException
	 */
	public function visibility(string $path): array ;

	/**
	 * @throws GoogleDriveException
	 */
	public function mimeType(string $path): array ;

	/**
	 * @throws GoogleDriveException
	 */
	public function lastModified(string $path): array ;

	/**
	 * @throws GoogleDriveException
	 */
	public function fileSize(string $path): array ;

	/**
	 * @param string $path
	 * @param bool   $deep
	 * @return iterable
	 * @throws GoogleDriveException
	 */
	public function listContents(string $path, bool $deep): iterable;

	/**
	 * @throws GoogleDriveException
	 */
	public function move(string $source, string $destination, Config $config): void;

	/**
	 * @throws GoogleDriveException
	 */
	public function copy(string $source, string $destination, Config $config): void;
}
