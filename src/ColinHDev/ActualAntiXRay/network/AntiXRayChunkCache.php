<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\network;

use ColinHDev\ActualAntiXRay\tasks\ChunkRequestTask;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function is_string;
use function spl_object_id;
use function strlen;

/**
 * Drop-in replacement for PocketMine's {@link ChunkCache}, which prepares chunks through our own
 * {@link ChunkRequestTask} instead of the vanilla one.
 *
 * Since PocketMine 5.x, chunk sending lives in NetworkSession::startUsingChunk(), which hardcodes
 * ChunkCache::getInstance(). Rather than reimplementing the whole player chunk-sending code (which
 * breaks on every PocketMine update), we register ourselves in ChunkCache's instance registry, so
 * the server transparently asks us for chunk payloads instead.
 */
final class AntiXRayChunkCache extends ChunkCache{

	/**
	 * @var self[][]
	 * @phpstan-var array<int, array<int, self>>
	 */
	private static array $instances = [];

	/**
	 * These closures are bound to ChunkCache's scope, which lets us read and write the private state
	 * inherited from it. We can't call ChunkCache's private constructor, so this is the only way to
	 * keep a single source of truth: ChunkCache::pruneCaches() and the world unload callback
	 * registered by ChunkCache::getInstance() both operate directly on that property.
	 *
	 * @phpstan-var \Closure(ChunkCache, int) : (CompressBatchPromise|string|null)
	 */
	private static \Closure $readCache;
	/** @phpstan-var \Closure(ChunkCache, int, CompressBatchPromise|string) : void */
	private static \Closure $writeCache;
	/** @phpstan-var \Closure(ChunkCache, int) : void */
	private static \Closure $deleteCache;
	/** @phpstan-var \Closure(ChunkCache, bool) : void */
	private static \Closure $countRequest;
	/** @phpstan-var \Closure(ChunkCache) : int */
	private static \Closure $cacheSize;

	/**
	 * Makes sure that chunks of the given world are prepared by this plugin for the given compressor.
	 * Calling this more than once for the same world/compressor pair is a no-op.
	 */
	public static function install(World $world, Compressor $compressor) : void{
		$worldId = spl_object_id($world);
		$compressorId = spl_object_id($compressor);
		if(isset(self::$instances[$worldId][$compressorId])){
			return;
		}

		//Let PocketMine create its own cache first. Doing so registers the world unload callback that
		//cleans up ChunkCache's instance registry, so we don't have to replicate that behaviour.
		ChunkCache::getInstance($world, $compressor);

		$cache = new self($world, $compressor);
		self::$instances[$worldId][$compressorId] = $cache;
		$world->addOnUnloadCallback(static function() use ($worldId) : void{
			unset(self::$instances[$worldId]);
		});

		$property = new \ReflectionProperty(ChunkCache::class, "instances");
		/**
		 * @var ChunkCache[][] $instances
		 * @phpstan-var array<int, array<int, ChunkCache>> $instances
		 */
		$instances = $property->getValue();
		$instances[$worldId][$compressorId] = $cache;
		$property->setValue(null, $instances);
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	private function __construct(
		private World $targetWorld,
		private Compressor $targetCompressor,
		private int $targetDimensionId = DimensionIds::OVERWORLD
	){
		//ChunkCache declares these as private promoted properties, and its constructor is private too,
		//so we initialise the inherited copies by hand to keep the object in a valid state.
		foreach(["world" => $targetWorld, "compressor" => $targetCompressor, "dimensionId" => $targetDimensionId] as $name => $value){
			(new \ReflectionProperty(ChunkCache::class, $name))->setValue($this, $value);
		}
		self::bindAccessors();
	}

	private static function bindAccessors() : void{
		if(isset(self::$readCache)){
			return;
		}
		$scope = ChunkCache::class;
		self::$readCache = \Closure::bind(
			static fn(ChunkCache $cache, int $chunkHash) : CompressBatchPromise|string|null => $cache->caches[$chunkHash] ?? null,
			null,
			$scope
		) ?? throw new AssumptionFailedError("Failed to bind closure to " . $scope);
		self::$writeCache = \Closure::bind(
			static function(ChunkCache $cache, int $chunkHash, CompressBatchPromise|string $value) : void{
				$cache->caches[$chunkHash] = $value;
			},
			null,
			$scope
		) ?? throw new AssumptionFailedError("Failed to bind closure to " . $scope);
		self::$deleteCache = \Closure::bind(
			static function(ChunkCache $cache, int $chunkHash) : void{
				unset($cache->caches[$chunkHash]);
			},
			null,
			$scope
		) ?? throw new AssumptionFailedError("Failed to bind closure to " . $scope);
		self::$countRequest = \Closure::bind(
			static function(ChunkCache $cache, bool $hit) : void{
				if($hit){
					++$cache->hits;
				}else{
					++$cache->misses;
				}
			},
			null,
			$scope
		) ?? throw new AssumptionFailedError("Failed to bind closure to " . $scope);
		self::$cacheSize = \Closure::bind(
			static function(ChunkCache $cache) : int{
				$result = 0;
				foreach($cache->caches as $entry){
					if(is_string($entry)){
						$result += strlen($entry);
					}
				}
				return $result;
			},
			null,
			$scope
		) ?? throw new AssumptionFailedError("Failed to bind closure to " . $scope);
	}

	/**
	 * Requests asynchronous preparation of the chunk at the given coordinates.
	 *
	 * @return CompressBatchPromise|string Compressed chunk packet, or a promise for one to be resolved asynchronously.
	 */
	public function request(int $chunkX, int $chunkZ) : CompressBatchPromise|string{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$cached = (self::$readCache)($this, $chunkHash);
		if($cached !== null){
			(self::$countRequest)($this, true);
			return $cached;
		}

		return $this->prepareChunkAsync($chunkX, $chunkZ, $chunkHash);
	}

	private function prepareChunkAsync(int $chunkX, int $chunkZ, int $chunkHash) : CompressBatchPromise{
		$this->targetWorld->registerChunkListener($this, $chunkX, $chunkZ);
		$chunk = $this->targetWorld->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			throw new \InvalidArgumentException("Cannot request an unloaded chunk");
		}
		(self::$countRequest)($this, false);

		$this->targetWorld->timings->syncChunkSendPrepare->startTiming();
		try{
			$promise = new CompressBatchPromise();

			$this->targetWorld->getServer()->getAsyncPool()->submitTask(
				new ChunkRequestTask(
					$this->targetWorld,
					$chunkX,
					$chunkZ,
					$this->targetDimensionId,
					$chunk,
					$promise,
					$this->targetCompressor
				)
			);
			(self::$writeCache)($this, $chunkHash, $promise);
			$promise->onResolve(function(CompressBatchPromise $promise) use ($chunkHash) : void{
				//the promise may have been discarded or replaced if the chunk was unloaded or modified in the meantime
				if((self::$readCache)($this, $chunkHash) === $promise){
					(self::$writeCache)($this, $chunkHash, $promise->getResult());
				}
			});

			return $promise;
		}finally{
			$this->targetWorld->timings->syncChunkSendPrepare->stopTiming();
		}
	}

	private function destroy(int $chunkX, int $chunkZ) : bool{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$existing = (self::$readCache)($this, $chunkHash);
		(self::$deleteCache)($this, $chunkHash);

		return $existing !== null;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function destroyOrRestart(int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$cache = (self::$readCache)($this, $chunkHash);
		if($cache === null){
			return;
		}
		if(!is_string($cache)){
			//some requesters are waiting for this chunk, so their request needs to be fulfilled
			$cache->cancel();
			(self::$deleteCache)($this, $chunkHash);

			$this->prepareChunkAsync($chunkX, $chunkZ, $chunkHash)->onResolve(...$cache->getResolveCallbacks());
		}else{
			//dump the cache, it'll be regenerated the next time it's requested
			$this->destroy($chunkX, $chunkZ);
		}
	}

	public function onChunkChanged(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->destroyOrRestart($chunkX, $chunkZ);
	}

	public function onBlockChanged(Vector3 $block) : void{
		$this->destroy($block->getFloorX() >> Chunk::COORD_BIT_SIZE, $block->getFloorZ() >> Chunk::COORD_BIT_SIZE);
	}

	public function onChunkUnloaded(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->destroy($chunkX, $chunkZ);
		$this->targetWorld->unregisterChunkListener($this, $chunkX, $chunkZ);
	}

	public function calculateCacheSize() : int{
		return (self::$cacheSize)($this);
	}
}
