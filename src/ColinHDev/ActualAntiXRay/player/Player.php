<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\player;

use ColinHDev\ActualAntiXRay\network\AntiXRayChunkCache;
use ColinHDev\ActualAntiXRay\ResourceManager;
use pocketmine\player\Player as PMMP_PLAYER;

class Player extends PMMP_PLAYER{

	/**
	 * Requests chunks from the world to be sent, up to a set limit every tick.
	 *
	 * Older versions of this plugin copied the whole method body from PocketMine-MP in order to hook
	 * into chunk sending. That broke on nearly every PocketMine update, so instead we now just make
	 * sure that our own chunk cache is registered for the world the player is currently in, and let
	 * PocketMine do the rest of the work as usual.
	 */
	protected function requestChunks() : void{
		if($this->isConnected() && ResourceManager::getInstance()->isEnabledForWorld($this->getWorld()->getFolderName())){
			AntiXRayChunkCache::install($this->getWorld(), $this->getNetworkSession()->getCompressor());
		}
		parent::requestChunks();
	}
}
