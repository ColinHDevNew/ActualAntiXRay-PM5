# ActualAntiXRay

This is an actual AntiXRay plugin for PocketMine-MP.

Unlike other so-called "AntiXRay" plugins, this one does not simply inform staff members if an ore is broken.
This plugin modifies the chunk packet sent to the client to make it look like a chunk contains a ridiculous amount of ores.

![example.png](example.png)
## Compatibility

This version targets **PocketMine-MP 5.44.x** (BedrockProtocol 58, Minecraft: Bedrock Edition 1.26.30)
and also runs on **Altay 5.44.x** (BedrockProtocol 60). The two protocol libraries disagree on the
signature of `LevelChunkPacket::create()`, which is detected once per worker thread at runtime.

Instead of reimplementing `Player::requestChunks()`, the plugin now registers its own
`AntiXRayChunkCache` in PocketMine's chunk cache registry, so the server asks it for chunk payloads
on its own. That keeps the plugin far less sensitive to changes in PocketMine's chunk sending code.
