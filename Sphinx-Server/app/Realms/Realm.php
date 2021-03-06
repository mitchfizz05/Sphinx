<?php

namespace App\Realms;
use App\Facades\SphinxNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;


/**
 * Minecraft server object.
 *
 * @property int $id Server ID, must be unique.
 * @property string $name Human readable name of server.
 * @property string $motd Message of the day (motd).
 * @property string $state Server state. Refer to STATE_* constants.
 * @property int $gamemode Server gamemode (uses in-game gamemode IDs)
 * @property bool $minigames_server Is the server a minigame server?
 * @property string $address Server IP.
 * @property Player[] $players An array of connected players.
 * @property Player[] $invited_players An array of players invited to join.
 * @property Player[] $operators An array of players who have operator status.
 *
 * @property int $days_left Remaining days of subscription (for Realms).
 * @property bool $expired Has the Realm expired (for Realms).
 * @property Player $owner The owner of the Realm.
 */
class Realm extends Model {
    use SoftDeletes;

    const STATE_ADMINLOCK = 'ADMIN_LOCK';
    const STATE_CLOSED = 'CLOSED';
    const STATE_OPEN = 'OPEN';
    const STATE_UNINITIALIZED = 'UNINITIALIZED';

    const GAMEMODE_SURVIVAL = 0;
    const GAMEMODE_CREATIVE = 1;
    const GAMEMODE_ADVENTURE = 2;
    const GAMEMODE_SPECTATOR = 3;

    const DIFFICULTY_PEACEFUL = 0;
    const DIFFICULTY_EASY = 1;
    const DIFFICULTY_NORMAL = 2;
    const DIFFICULTY_HARD = 3;

    protected $guarded = [];

    protected $dates = ['deleted_at'];

    protected $table = 'servers';

    /**
     * Should updates be automatically pushed to the Sphinx Node?
     *
     * @var bool
     */
    public $autoPush = true;

    /**
     * Check if a player is invited to this Realm.
     *
     * @param Player $player
     * @return bool
     */
    public function isInvited($player)
    {
        foreach ($this->invited_players as $invited_player) {
            if ($invited_player->uuid == $player->uuid) {
                // Invited! Yay. :3
                return true;
            }
        }

        // Not invited. :(
        return false;
    }

    /**
     * Push changes to the Sphinx Node.
     */
    public function push()
    {
        SphinxNode::sendManifest([$this->id]);
    }

    /**
     * Add a new player to the Realm.
     *
     * @param Player $player
     */
    public function addPlayer($player)
    {
        if ($this->isInvited($player)) {
            // Already invited
            return;
        }

        $invited = $this->invited_players;
        $invited[] = $player;
        $this->invited_players = $player;

        $this->save();
    }

    /**
     * Remove a player from the Realm.
     *
     * @param Player $player
     */
    public function removePlayer($player)
    {
        // Remove player from invited list.
        $invited = $this->invited_players;
        foreach ($invited as $i => $invited_player) {
            if ($invited_player->uuid == $player->uuid) {
                unset($invited[$i]);
            }
        }
        $this->invited_players = array_values($invited);

        // Also deop player.
        $this->deopPlayer($player);

        $this->save();
    }

    /**
     * Check if a player is an operator.
     *
     * @param Player $player
     * @return bool
     */
    public function isOp($player)
    {
        foreach ($this->operators as $op) {
            if ($op->uuid == $player->uuid) {
                // Already oped.
                return true;
            }
        }

        // Not operator.
        return false;
    }

    /**
     * Give a player operator status.
     *
     * @param Player $player
     */
    public function opPlayer($player)
    {
        if ($this->isOp($player)) {
            // Already oped
            return;
        }

        $ops = $this->operators;
        $ops[] = $player;
        $this->operators = $ops;

        $this->save();
    }

    /**
     * Revoke operator status from a player.
     *
     * @param Player $player
     */
    public function deopPlayer($player)
    {
        $ops = $this->operators;

        foreach ($ops as $i => $op) {
            if ($op->uuid == $player->uuid) {
                unset($ops[$i]);
            }
        }

        $this->operators = array_values($ops);

        $this->save();
    }

    /**
     * Save without pushing manifest.
     */
    public function silentSave()
    {
        $this->autoPush = false;
        $this->save();
        $this->autoPush = true;
    }

    /**
     * Invites relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function invites()
    {
        return $this->hasMany('App\Realms\Invite', 'realm_id');
    }

    /**
     * Worlds relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function worlds()
    {
        return $this->hasMany('App\Realms\World', 'realm_id');
    }

    /**
     * Mutator for owner attribute, encodes player objects into json.
     *
     * @param $value
     * @return string
     */
    public function setOwnerAttribute($value)
    {
        $this->attributes['owner'] = json_encode([
            'uuid' => $value->uuid,
            'username' => $value->username
        ]);
    }

    /**
     * Accessor for owner attribute, decodes player objects from json.
     *
     * @param $value
     * @return Player
     */
    public function getOwnerAttribute($value)
    {
        $playerJson = json_decode($value);
        return new Player(
            $playerJson->uuid,
            $playerJson->username
        );
    }

    /**
     * Mutator for players attribute.
     *
     * @param Player[] $value
     * @param string $attribute Name of attribute to mutate, allows reuse of this method.
     * @return string
     */
    public function setInvitedPlayersAttribute($value, $attribute = 'invited_players')
    {
        $playerJson = [];

        foreach ($value as $player)
        {
            $playerJson[] = [
                'uuid' => $player->uuid,
                'username' => $player->username
            ];
        }

        $this->attributes[$attribute] = json_encode($playerJson);
    }

    /**
     * Accessor for player attribute.
     *
     * @param array $value
     * @return Player[]
     */
    public function getInvitedPlayersAttribute($value)
    {
        $players = [];

        foreach (json_decode($value) as $playerJson)
        {
            $players[] = new Player(
                $playerJson->uuid,
                $playerJson->username
            );
        }

        return $players;
    }

    /**
     * Mutator for ops attribute. Wraps around player's mutator.
     *
     * @param Player[] $value
     * @return string
     */
    public function setOperatorsAttribute($value)
    {
        $this->setInvitedPlayersAttribute($value, 'operators');
    }

    /**
     * Accessor for ops attribute. Wraps around player's accessor.
     *
     * @param array $value
     * @return Player[]
     */
    public function getOperatorsAttribute($value)
    {
        return $this->getInvitedPlayersAttribute($value);
    }
}
