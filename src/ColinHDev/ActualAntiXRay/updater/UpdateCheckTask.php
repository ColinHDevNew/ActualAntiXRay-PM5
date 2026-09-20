<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\updater;

use ColinHDev\ActualAntiXRay\ActualAntiXRay;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Internet;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function mkdir;
use function preg_match;
use function rename;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function unlink;
use function version_compare;
use const JSON_THROW_ON_ERROR;

/**
 * Asks the GitHub API for the newest release of the plugin and, if it is newer than the running version, downloads its
 * .phar asset into the staging folder. Everything happens off the main thread, so a slow or unreachable GitHub only
 * delays the update, never the server.
 *
 * @see Updater
 */
final class UpdateCheckTask extends AsyncTask{

	public const STATUS_UP_TO_DATE = "up-to-date";
	public const STATUS_AVAILABLE = "available";
	public const STATUS_DOWNLOADED = "downloaded";
	public const STATUS_ERROR = "error";

	private const PLUGIN_KEY = "plugin";

	private const API_TIMEOUT = 10;
	private const DOWNLOAD_TIMEOUT = 120;

	public function __construct(
		ActualAntiXRay $plugin,
		private string $repository,
		private string $pluginName,
		private string $currentVersion,
		private string $token,
		private bool $allowPreReleases,
		private bool $download,
		private string $stagingFolder
	){
		$this->storeLocal(self::PLUGIN_KEY, $plugin);
	}

	public function onRun() : void{
		try{
			$this->setResult($this->check());
		}catch(\Throwable $e){
			$this->setResult(["status" => self::STATUS_ERROR, "message" => $e->getMessage()]);
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function check() : array{
		if(preg_match('/^[\w.\-]+\/[\w.\-]+$/', $this->repository) !== 1){
			return ["status" => self::STATUS_ERROR, "message" => "\"" . $this->repository . "\" is not a valid GitHub repository"];
		}

		$release = $this->fetchRelease();
		if(is_string($release)){
			return ["status" => self::STATUS_ERROR, "message" => $release];
		}

		$tag = $release["tag_name"] ?? null;
		if(!is_string($tag) || preg_match('/\d+(?:\.\d+)+(?:[.\-+][0-9A-Za-z.\-]+)?/', $tag, $matches) !== 1){
			return ["status" => self::STATUS_ERROR, "message" => "No version number found in the newest release"];
		}
		$version = $matches[0];
		$url = is_string($release["html_url"] ?? null) ? $release["html_url"] : "https://github.com/" . $this->repository . "/releases";

		if(!version_compare($version, $this->currentVersion, ">")){
			return ["status" => self::STATUS_UP_TO_DATE, "version" => $version];
		}
		if(!$this->download){
			return ["status" => self::STATUS_AVAILABLE, "version" => $version, "url" => $url];
		}

		$assetUrl = $this->findPharAsset($release);
		if($assetUrl === null){
			return ["status" => self::STATUS_AVAILABLE, "version" => $version, "url" => $url, "message" => "the release has no .phar file attached"];
		}

		$message = "";
		$path = $this->downloadPhar($assetUrl, $version, $message);
		if($path === null){
			return ["status" => self::STATUS_ERROR, "message" => "Could not download version " . $version . ": " . $message];
		}
		return ["status" => self::STATUS_DOWNLOADED, "version" => $version, "url" => $url, "path" => $path];
	}

	/**
	 * Returns the newest release as decoded JSON, or an error message.
	 *
	 * @return array<string, mixed>|string
	 */
	private function fetchRelease() : array|string{
		$endpoint = "https://api.github.com/repos/" . $this->repository . "/releases";
		// "/latest" already skips drafts and pre-releases; the list has to be filtered by hand.
		$endpoint .= $this->allowPreReleases ? "?per_page=10" : "/latest";

		$headers = ["Accept: application/vnd.github+json", "X-GitHub-Api-Version: 2022-11-28"];
		if($this->token !== ""){
			$headers[] = "Authorization: Bearer " . $this->token;
		}

		$response = Internet::getURL($endpoint, self::API_TIMEOUT, $headers, $error);
		if($response === null){
			return is_string($error) ? $error : "request failed";
		}
		if($response->getCode() !== 200){
			$message = "GitHub answered with HTTP " . $response->getCode();
			if($response->getCode() === 403 || $response->getCode() === 429){
				$message .= " (API rate limit reached, set a token in the config to raise it)";
			}elseif($response->getCode() === 404){
				$message .= " (no release found for " . $this->repository . ")";
			}
			return $message;
		}

		try{
			$data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			return "GitHub sent malformed JSON: " . $e->getMessage();
		}
		if(!is_array($data)){
			return "GitHub sent an unexpected response";
		}

		if(!$this->allowPreReleases){
			/** @var array<string, mixed> $data */
			return $data;
		}
		foreach($data as $release){
			if(is_array($release) && ($release["draft"] ?? false) !== true){
				/** @var array<string, mixed> $release */
				return $release;
			}
		}
		return "no release found for " . $this->repository;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function findPharAsset(array $release) : ?string{
		$assets = $release["assets"] ?? null;
		if(!is_array($assets)){
			return null;
		}
		$fallback = null;
		foreach($assets as $asset){
			if(!is_array($asset)){
				continue;
			}
			$name = $asset["name"] ?? null;
			$url = $asset["browser_download_url"] ?? null;
			if(!is_string($name) || !is_string($url) || !str_ends_with(strtolower($name), ".phar")){
				continue;
			}
			// Releases may ship several .phar files; the one named after this plugin wins.
			if(str_contains(strtolower($name), strtolower($this->pluginName))){
				return $url;
			}
			$fallback ??= $url;
		}
		return $fallback;
	}

	/**
	 * Downloads the .phar into the staging folder and returns its path, or null if the download is unusable.
	 *
	 * @phpstan-param mixed $error
	 * @phpstan-param-out string $error
	 */
	private function downloadPhar(string $assetUrl, string $version, &$error) : ?string{
		// No token here on purpose: the asset URL redirects to another host, and cURL forwards custom headers along.
		$response = Internet::getURL($assetUrl, self::DOWNLOAD_TIMEOUT, [], $curlError);
		if($response === null){
			$error = is_string($curlError) ? $curlError : "request failed";
			return null;
		}
		if($response->getCode() !== 200){
			$error = "HTTP " . $response->getCode();
			return null;
		}

		if(!is_dir($this->stagingFolder) && !@mkdir($this->stagingFolder, 0777, true) && !is_dir($this->stagingFolder)){
			$error = "could not create " . $this->stagingFolder;
			return null;
		}
		$path = $this->stagingFolder . $this->pluginName . "-" . str_replace(["/", "\\"], "_", $version) . ".phar";
		$temporary = $path . ".part";
		@unlink($temporary);
		if(@file_put_contents($temporary, $response->getBody()) === false){
			$error = "could not write " . $temporary;
			return null;
		}
		@unlink($path);
		if(!@rename($temporary, $path)){
			@unlink($temporary);
			$error = "could not write " . $path;
			return null;
		}

		// Opening the archive verifies its signature, so a truncated or tampered download is caught here.
		$description = Updater::describePhar($path);
		if($description === null || $description->getName() !== $this->pluginName){
			@unlink($path);
			$error = "the downloaded file is not a valid " . $this->pluginName . " .phar";
			return null;
		}
		if(!version_compare($description->getVersion(), $this->currentVersion, ">")){
			@unlink($path);
			$error = "the release is tagged " . $version . " but contains version " . $description->getVersion();
			return null;
		}
		return $path;
	}

	public function onCompletion() : void{
		$plugin = $this->fetchLocal(self::PLUGIN_KEY);
		if(!$plugin instanceof ActualAntiXRay || !$plugin->isEnabled()){
			return;
		}
		$result = $this->getResult();
		if(is_array($result)){
			/** @var array<string, string> $result */
			$plugin->getUpdater()->handleCheckResult($result);
		}
	}
}
