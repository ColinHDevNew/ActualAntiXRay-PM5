<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\tasks;

use pmmp\encoding\ByteBufferWriter;
use pmmp\thread\ThreadSafeArray;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\ChunkRequestTask as PMMPChunkRequestTask;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\format\SubChunk;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\World;
use function array_map;
use function chr;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function is_string;
use function mt_rand;

class ChunkRequestTask extends PMMPChunkRequestTask{

	/**
	 * Whether LevelChunkPacket::create() uses the 7-argument signature. Resolved once per worker
	 * thread; this is a plain static, so it is never shared across threads.
	 */
	private static ?bool $extendedLevelChunkPacket = null;

	private ThreadSafeArray $replaceableBlocks;
	private ThreadSafeArray $replacingBlocks;

	/** @phpstan-var DimensionIds::* */
	private int $targetDimensionId;

	private int $worldMinY;
	private int $worldMaxY;

	private string $adjacentChunks;
	private string $serializedTiles;

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public function __construct(World $world, int $chunkX, int $chunkZ, int $dimensionId, Chunk $chunk, CompressBatchPromise $promise, Compressor $compressor){
		parent::__construct($chunkX, $chunkZ, $dimensionId, $chunk, $promise, $compressor);

		$this->replaceableBlocks = ThreadSafeArray::fromArray([
			VanillaBlocks::STONE()->getStateId() => true,
			VanillaBlocks::DIRT()->getStateId() => true,
			VanillaBlocks::GRAVEL()->getStateId() => true
		]);
		$this->replacingBlocks = ThreadSafeArray::fromArray([
			VanillaBlocks::COAL_ORE()->getStateId(),
			VanillaBlocks::IRON_ORE()->getStateId(),
			VanillaBlocks::LAPIS_LAZULI_ORE()->getStateId(),
			VanillaBlocks::REDSTONE_ORE()->getStateId(),
			VanillaBlocks::GOLD_ORE()->getStateId(),
			VanillaBlocks::DIAMOND_ORE()->getStateId(),
			VanillaBlocks::EMERALD_ORE()->getStateId()
		]);

		//PocketMine's ChunkRequestTask keeps the dimension ID and the serialized tiles private, so we
		//need our own copies. They are deliberately named differently to avoid shadowing the parent's
		//properties, which would be ambiguous for pmmpthread's thread-safe property store.
		$this->targetDimensionId = $dimensionId;
		$this->serializedTiles = ChunkSerializer::serializeTiles($chunk);

		$this->worldMinY = $world->getMinY();
		$this->worldMaxY = $world->getMaxY();

		$adjacentChunks = [];
		for($x = -1; $x <= 1; $x++){
			for($z = -1; $z <= 1; $z++){
				if($x === 0 && $z === 0){
					continue;
				}
				if($x !== 0 && $z !== 0){
					//only the four directly adjacent chunks are needed to look at this chunk's edges
					continue;
				}
				$cx = $chunkX + $x;
				$cz = $chunkZ + $z;
				$temporaryChunkLoader = new class implements ChunkLoader{};
				$world->registerChunkLoader($temporaryChunkLoader, $cx, $cz);
				$adjacentChunks[World::chunkHash($x, $z)] = $world->loadChunk($cx, $cz);
				$world->unregisterChunkLoader($temporaryChunkLoader, $cx, $cz);
			}
		}
		$this->adjacentChunks = igbinary_serialize(
			array_map(
				static fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
				$adjacentChunks
			)
		) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	public function onRun() : void{
		//NOTE: the keys used here are RELATIVE chunk hashes (-1..1), not absolute ones. array_merge() must
		//not be used to combine them, as it renumbers integer keys and would corrupt the coordinates.
		$chunks = [World::chunkHash(0, 0) => FastChunkSerializer::deserializeTerrain($this->chunk)];
		$adjacentChunks = igbinary_unserialize($this->adjacentChunks);
		if(is_array($adjacentChunks)){
			foreach($adjacentChunks as $relativeChunkHash => $serialized){
				if(is_string($serialized)){
					$chunks[$relativeChunkHash] = FastChunkSerializer::deserializeTerrain($serialized);
				}
			}
		}

		$manager = new SimpleChunkManager($this->worldMinY, $this->worldMaxY);
		foreach($chunks as $relativeChunkHash => $chunk){
			$relativeChunkX = null;
			$relativeChunkZ = null;
			World::getXZ($relativeChunkHash, $relativeChunkX, $relativeChunkZ);
			$manager->setChunk($this->chunkX + $relativeChunkX, $this->chunkZ + $relativeChunkZ, $chunk);
		}

		$explorer = new SubChunkExplorer($manager);
		for($subChunkY = Chunk::MIN_SUBCHUNK_INDEX; $subChunkY <= Chunk::MAX_SUBCHUNK_INDEX; $subChunkY++){
			$explorer->moveToChunk($this->chunkX, $subChunkY, $this->chunkZ);
			if(!($explorer->currentSubChunk instanceof SubChunk) || $explorer->currentSubChunk->isEmptyFast()){
				continue;
			}

			for($x = 0; $x < SubChunk::EDGE_LENGTH; $x++){
				for($z = 0; $z < SubChunk::EDGE_LENGTH; $z++){
					for($y = 0; $y < SubChunk::EDGE_LENGTH; $y++){

						//never touch the lowest and highest layer of the world, as there is no block
						//below / above them to look at
						if($subChunkY === Chunk::MIN_SUBCHUNK_INDEX && $y === 0){
							continue;
						}
						if($subChunkY === Chunk::MAX_SUBCHUNK_INDEX && $y === SubChunk::EDGE_LENGTH - 1){
							continue;
						}

						$vector = new Vector3($x, $y, $z);
						if(!$this->isBlockReplaceable($explorer, $vector, $subChunkY)){
							// If the current block is not replaceable, we can increment the y coordinate by one,
							// as we can skip the following loop which would check that block again as block below.
							$y++;
							continue;
						}

						// We could use the random_int() function instead but since mt_rand() is faster than random_int(),
						// we use that as it is not important if our the returned values are cryptographically secure.
						if(mt_rand(1, 100) > 75){
							continue;
						}

						foreach(Facing::ALL as $facing){
							$blockSide = $vector->getSide($facing);
							if(!$this->isBlockReplaceable($explorer, $blockSide, $subChunkY)){
								if($facing === Facing::UP){
									// If the block above is not replaceable, we can increment the y coordinate by two,
									// as we can skip the following two loops which would check that block again.
									// First, as the "main" block, then as the block below.
									$y += 2;
									continue 2;
								}
								continue 2;
							}
						}

						//isBlockReplaceable() leaves the explorer pointing at the last side it checked, which may
						//belong to a neighbouring (sub)chunk, so we have to move it back before writing anything.
						$explorer->moveToChunk($this->chunkX, $subChunkY, $this->chunkZ);
						if(!($explorer->currentSubChunk instanceof SubChunk)){
							continue;
						}
						$explorer->currentSubChunk->setBlockStateId($x, $y, $z, $this->replacingBlocks[mt_rand(0, count($this->replacingBlocks) - 1)]);
					}
				}
			}
		}

		$chunk = $manager->getChunk($this->chunkX, $this->chunkZ);
		if(!($chunk instanceof Chunk)){
			throw new AssumptionFailedError("The chunk to be serialized disappeared from the chunk manager");
		}

		$converter = TypeConverter::getInstance();
		$subCount = ChunkSerializer::getSubChunkCount($chunk, $this->targetDimensionId);
		$payload = ChunkSerializer::serializeFullChunk($chunk, $this->targetDimensionId, $converter->getBlockTranslator(), $this->serializedTiles);

		$stream = new ByteBufferWriter();
		PacketBatch::encodePackets($stream, [$this->createLevelChunkPacket($subCount, $payload)]);

		$compressor = $this->compressor->deserialize();
		$this->setResult(chr($compressor->getNetworkId()) . $compressor->compress($stream->getData()));
	}

	/**
	 * Builds the LevelChunkPacket in a way that works with both BedrockProtocol flavours currently in
	 * the wild, since their create() signatures diverged:
	 *
	 * - pmmp/bedrock-protocol 58 (PocketMine-MP 5.44):
	 *   create(ChunkPosition, int $dimensionId, int $subChunkCount, bool $clientSubChunkRequestsEnabled, ?array $usedBlobHashes, string $extraPayload)
	 * - altayofficial/bedrock-protocol 60 (Altay 5.44):
	 *   create(ChunkPosition, int $dimensionId, int $subChunkCount, ?int $clientRequestSubChunkLimit, bool $cacheEnabled, array $usedBlobHashes, string $extraPayload)
	 *
	 * In both cases we want "no sub-chunk requests, no blob cache", it's just spelled differently.
	 */
	private function createLevelChunkPacket(int $subCount, string $payload) : LevelChunkPacket{
		self::$extendedLevelChunkPacket ??= count((new \ReflectionMethod(LevelChunkPacket::class, "create"))->getParameters()) >= 7;

		$position = new ChunkPosition($this->chunkX, $this->chunkZ);
		if(self::$extendedLevelChunkPacket){
			return LevelChunkPacket::create($position, $this->targetDimensionId, $subCount, null, false, [], $payload);
		}
		return LevelChunkPacket::create($position, $this->targetDimensionId, $subCount, false, null, $payload);
	}

	private function isBlockReplaceable(SubChunkExplorer $explorer, Vector3 $vector, int $subChunkY) : bool{
		$chunkX = $this->chunkX;
		$x = $vector->getFloorX();
		if($x < 0){
			$x = SubChunk::EDGE_LENGTH - 1;
			$chunkX--;
		}elseif($x >= SubChunk::EDGE_LENGTH){
			$x = 0;
			$chunkX++;
		}

		$chunkZ = $this->chunkZ;
		$z = $vector->getFloorZ();
		if($z < 0){
			$z = SubChunk::EDGE_LENGTH - 1;
			$chunkZ--;
		}elseif($z >= SubChunk::EDGE_LENGTH){
			$z = 0;
			$chunkZ++;
		}

		$y = $vector->getFloorY();
		if($y < 0){
			$y = SubChunk::EDGE_LENGTH - 1;
			$subChunkY--;
		}elseif($y >= SubChunk::EDGE_LENGTH){
			$y = 0;
			$subChunkY++;
		}

		$explorer->moveToChunk($chunkX, $subChunkY, $chunkZ);
		if($explorer->currentSubChunk instanceof SubChunk){
			return isset($this->replaceableBlocks[$explorer->currentSubChunk->getBlockStateId($x, $y, $z)]);
		}
		return false;
	}
}
