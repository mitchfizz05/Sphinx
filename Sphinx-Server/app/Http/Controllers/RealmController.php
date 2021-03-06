<?php

namespace App\Http\Controllers;

use App\Facades\SphinxNode;
use App\Realms\Player;
use App\Realms\Realm;
use App\Realms\Invite;
use App\Realms\World;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RealmController extends Controller
{
    /**
     * Generate a JSON response to be packaged up and sent to the client.
     * NOTE: Does not return encoded JSON. JSON must manually be encoded with json_encode().
     * @param Realm $server
     * @return array
     */
    public static function generateServerJSON($server) {
        // Generate player list.
        $players = array();
        foreach ($server->invited_players as $player) {
            $players[] = array(
                'name' => $player->username,
                'uuid' => $player->uuid,
                'operator' => in_array($player, $server->operators)
            );
        }

        // Generate slots JSON.
        $slots = [];
        $firstSlotId = null;
        foreach ($server->worlds as $world) {
            if ($firstSlotId === null) {
                $firstSlotId = $world->slot_id;
            }

            $slots[] = [
                'slotId' => $world->slot_id,

                'options' => json_encode([
                    'slotName' => $world->name,
                    'minecraftVersion' => '1.9',

                    'pvp' => !!$world->pvp,
                    'spawnAnimals' => !!$world->spawn_animals,
                    'spawnMonsters' => !!$world->spawn_monsters,
                    'spawnNPCs' => !!$world->spawn_npcs,
                    'spawnProtection' => $world->spawn_protection,
                    'commandBlocks' => !!$world->command_blocks,
                    'forceGameMode' => !!$world->force_gamemode,
                    'difficulty' => $world->difficulty,
                    'gameMode' => $world->gamemode
                ])
            ];
        }

        if ($firstSlotId === null) {
            $firstSlotId = 1;
        }
		if ($server->days_left < 1)
        {
			$expired = 1;
		} 
		else 
		{
			$expired = 0;
			
		}
		// Formulate JSON response.
        $json = array(
            'id' => intval($server->id),
            'remoteSubscriptionId' => "$server->id",
            'name' => $server->name,
            'players' => $players,
            'motd' => $server->motd,
            'state' => $server->state,
            'owner' => $server->owner->username,
            'ownerUUID' => $server->owner->uuid,
            'daysLeft' => intval($server->days_left),
            'ip' => $server->address,
            'expired' => !!$expired,
            'minigame' => !!$server->minigames_server,
            'activeSlot' => $firstSlotId,
            'slots' => $slots
        );

        return $json;
    }

    /**
     * Return a listing of all Realms available to the player.
     *
     * @return array
     */
    public function listing()
    {
        $servers = Realm::all();

        // Generate JSON
        $serverlistJson = [];
        foreach ($servers as $server) {
            // Check if we are invited to this server.
            if (!$server->isInvited(Player::current())) {
                // Not invited. :(
                continue;
            }

            $serverlistJson[] = self::generateServerJSON($server);
        }

        return [
            'servers' => $serverlistJson
        ];
    }

    /**
     * View a single server.
     *
     * @param int $serverId Server ID
     * @return array
     */
    public function view($serverId)
    {
        return self::generateServerJSON(Realm::findOrFail($serverId));
    }

    /**
     * Leave a Realm you've been invited to.
     *
     * @param int $serverId Server ID
     * @return mixed
     */
    public function leave($serverId)
    {
        if (!Player::isLoggedIn()) {
            abort(401); // 401 Unauthorized - not logged in!
        }

        $server = Realm::find($serverId);

        // Ensure the owner isn't removing themselves from their own Realm.
        if (Player::current()->uuid == $server->owner->uuid) {
            abort(400); // 400 Bad Request
        }

        // Remove user from invited players list.
        $server->removePlayer(Player::current());

        return '';
    }

    /**
     * Remove a user from the Realm. As in, de-whitelist.
     *
     * @param int $serverId Server ID
     * @param Player $playerUuid Player UUID
     * @return mixed
     */
    public function kick($serverId, $playerUuid)
    {
        if (!Player::isLoggedIn()) {
            abort(401); // 401 Unauthorized - not logged in!
        }

        $server = Realm::findOrFail($serverId);

        // Check user owns server.
        if (Player::current()->uuid != $server->owner->uuid) {
            abort(403); // 403 Forbidden
        }

        // Remove user from Realm.
        $server->removePlayer(new Player($playerUuid, null));

        return '';
    }

    /**
     * Close the Realm, making it unavailable to join and shutting down the server.
     *
     * @param int $serverId Server ID
     * @return mixed
     */
	public function close($serverId)
    {
        $server = Realm::find($serverId);

	    if (Player::current()->uuid != $server->owner->uuid) {
            abort(403); // 403 Forbidden
        }

        if (!Player::isLoggedIn()) {
            abort(401); // 401 Unauthorized - not logged in!
        }

        // Change State.
        $server->state = "CLOSED";
        $server->silentSave(); // save without pushing changes
        SphinxNode::sendManifest([$server->id], true);

        return 'true';
    }

    /**
     * Open the Realm, making it available to join once more.
     *
     * @param int $serverId Server ID
     * @return mixed
     */
	public function open($serverId)
    {
        $server = Realm::find($serverId);

        if (Player::current()->uuid != $server->owner->uuid) {
            abort(403); // 403 Forbidden
        }

        if (!Player::isLoggedIn()) {
            abort(401); // 401 Unauthorized - not logged in!
        }

        // Change State.
        $server->state = "OPEN";
        $server->silentSave(); // save without pushing changes
        SphinxNode::sendManifest([$server->id], true);

        return 'true';
    }

    /**
     * Join a server.
     *
     * @param int $serverId
     * @return mixed
     */
    public function join($serverId)
    {
        $server = Realm::findOrFail($serverId);
        if (!$server->isInvited(Player::current())) {
            // Not invited. Sorry! :(
            abort(403); // 403 Forbidden.
        }

        return [
            'address' => SphinxNode::joinServer($server->id)
        ];
    }
	
	public function UpdateServerInfo(Request $request,$serverId)
    {
        $server = Realm::find($serverId);

		if (Player::current()->uuid != $server->owner->uuid) {
            abort(403); // 403 Forbidden
        }

        if (!Player::isLoggedIn()) {
            abort(401); // 401 Unauthorized - not logged in!
        }

        // Change Server name and desc.
        $server->name = $request->input('name');
		$server->motd = $request->input('description');
        $server->Save(); // save!

        return 'true';
    }
	
	public function InitServer(Request $request,$serverId)
    {
        $server = Realm::find($serverId);

		if (Player::current()->uuid != $server->owner->uuid) {
            abort(403); // 403 Forbidden
        }

        if (!Player::isLoggedIn()) {
            abort(401); // 401 Unauthorized - not logged in!
        }

        // setting the realm to closed and setting the name and desc.
        $server->name = $request->input('name');
		$server->motd = $request->input('description');
		$server->state = "CLOSED";
        $server->Save(); // save!

        return 'true';
    }
		public function MakeTrialWorld(Request $request)
		
    {
		$player = new Player(Player::current()->uuid, Player::current()->username);
        // Make a Trial World
		Realm::create([
            'address' => '',
            'state' => Realm::STATE_OPEN,
            'name' => $request->input('name'),
            'days_left' => 365,
            'expired' => false,
            'invited_players' => [$player],
            'operators' => [$player],
            'minigames_server' => false,
            'motd' => $request->input('description'),
            'owner' => $player
        ]);
        return 'true';
    }
    public function updateSlot(Request $request, $serverId, $slotId)
    {
        $server = Realm::find($serverId);

        if (Player::current()->uuid != $server->owner->uuid) {
            abort(403); // 403 Forbidden
        }

        if (!Player::isLoggedIn()) {
            abort(401); // 401 Unauthorized - not logged in!
        }

        // Get the world.
        $world = Realm::findOrFail($serverId)->worlds->where('slot_id', $slotId)->first();
        if ($world === null) {
            // Slot doesn't exist.
            abort(404); // 404 Not Found.
        }

        // Available options to be set and what they map to on the model.
        $optionMapping = [
            'slotName' => 'name',
            'pvp' => 'pvp',
            'spawnAnimals' => 'spawn_animals',
            'spawnMonsters' => 'spawn_monsters',
            'spawnNPCs' => 'spawn_npcs',
            'spawnProtection' => 'spawn_protection',
            'commandBlocks' => 'command_blocks',
            'forceGameMode' => 'force_gamemode',
            'difficulty' => 'difficulty',
            'gameMode' => 'gamemode',
        ];

        // New options to be set.
        $newOptions = [
            'name' => World::DEFAULT_NAME,
            'pvp' => World::DEFAULT_PVP,
            'gamemode' => World::DEFAULT_GAMEMODE,
            'spawn_animals' => World::DEFAULT_SPAWN_ANIMALS,
            'difficulty' => World::DEFAULT_DIFFICULTY,
            'spawn_monsters' => World::DEFAULT_SPAWN_MONSTERS,
            'spawn_protection' => World::DEFAULT_SPAWN_PROTECTION,
            'spawn_npcs' => World::DEFAULT_SPAWN_NPCS,
            'force_gamemode' => World::DEFAULT_FORCE_GAMEMODE,
            'command_blocks' => World::DEFAULT_COMMAND_BLOCKS
        ];

        // Options that do not require a server restart.
        $restartExemptOptions = ['name'];

        // Options that have been changed.
        $changedOptions = [];

        // Make changes.
        foreach ($request->all() as $key => $value) {
            if (isset($optionMapping[$key])) {
                $dbKey = $optionMapping[$key];
                $newOptions[$dbKey] = $value;
            }
        }

        // Determine changed values.
        foreach ($newOptions as $key => $value) {
            if ($world->$key != $value) {
                // I'm different! :3
                $changedOptions[] = $key;
            }
        }

        // Apply changes to model.
        foreach ($newOptions as $key => $value) {
            $world->$key = $value;
        }

        // Save everything.
        $world->save();
        if (array_diff($changedOptions, $restartExemptOptions)) {
            // A change requires a server restart.
            $server->silentSave();
            SphinxNode::sendManifest([$serverId], true); // send manifest with flag to restart server.
        } else {
            // Change does not require a server restart.
            $server->save();
        }
    }
}


