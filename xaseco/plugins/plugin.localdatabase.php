<?php
/* vim: set noexpandtab tabstop=2 softtabstop=2 shiftwidth=2: */

/**
 * This script saves record into a local database.
 * You can modify this file as you want, to advance
 * the information stored in the database!
 *
 * @author    Florian Schnell
 * @version   2.0
 * Updated by Xymph
 *
 * Dependencies: requires plugin.panels.php on TMF
 */

Aseco::registerEvent('onStartup', 'ldb_loadSettings');
Aseco::registerEvent('onStartup', 'ldb_connect');
Aseco::registerEvent('onEverySecond', 'ldb_reconnect');
Aseco::registerEvent('onSync', 'ldb_sync');
Aseco::registerEvent('onNewChallenge', 'ldb_newChallenge');
Aseco::registerEvent('onPlayerConnect', 'ldb_playerConnect');
Aseco::registerEvent('onPlayerDisconnect', 'ldb_playerDisconnect');
Aseco::registerEvent('onPlayerFinish', 'ldb_playerFinish');
Aseco::registerEvent('onPlayerWins', 'ldb_playerWins');

// called @ onStartup
function ldb_loadSettings($aseco) {
	global $ldb_settings;

	$aseco->console('[LocalDB] Load config file [localdatabase.xml]');
	if (!$settings = $aseco->xml_parser->parseXml('localdatabase.xml')) {
		trigger_error('Could not read/parse Local database config file localdatabase.xml !', E_USER_ERROR);
	}
	$settings = $settings['SETTINGS'];

	// read mysql server settings
	$ldb_settings['mysql']['host'] = $settings['MYSQL_SERVER'][0];
	$ldb_settings['mysql']['login'] = $settings['MYSQL_LOGIN'][0];
	$ldb_settings['mysql']['password'] = $settings['MYSQL_PASSWORD'][0];
	$ldb_settings['mysql']['database'] = $settings['MYSQL_DATABASE'][0];
	$ldb_settings['mysql']['connection'] = false;

	// display records in game?
	if (strtoupper($settings['DISPLAY'][0]) == 'TRUE')
		$ldb_settings['display'] = true;
	else
		$ldb_settings['display'] = false;

	// set highest record still to be displayed
	$ldb_settings['limit'] = $settings['LIMIT'][0];

	$ldb_settings['messages'] = $settings['MESSAGES'][0];
}  // ldb_loadSettings

// called @ onStartup
function ldb_connect($aseco) {
	global $maxrecs;

	// get the settings
	global $ldb_settings;
	// create data fields
	global $ldb_records;
	$ldb_records = new RecordList($maxrecs);
	global $ldb_challenge;
	$ldb_challenge = new Challenge();

	// log status message
	$aseco->console("[LocalDB] Try to connect to PostgreSQL server on '{1}' with database '{2}'",
	                $ldb_settings['mysql']['host'], $ldb_settings['mysql']['database']);

	if (!$ldb_settings['mysql']['connection'] = pg_connect("host=localhost port=5432 dbname=aseco user=aseco password=Q0!2CFxGejfRKVW7")) {
		trigger_error('[LocalDB] Could not authenticate at PostgreSQL server!', E_USER_ERROR);
	}


	// log status message
	$aseco->console('[LocalDB] PostgreSQL Server Version is ');
	// optional UTF-8 handling fix
	//pg_query('SET NAMES utf8');
	$aseco->console('[LocalDB] Checking database structure...');

	// create main tables
	$query = "CREATE TABLE IF NOT EXISTS challenges (
	            Id serial primary key,
	            Uid varvarchar(27) NOT NULL default '',
	            Name varvarchar(100) NOT NULL default '',
	            Author varvarchar(30) NOT NULL default '',
	            Environment varvarchar(10) NOT NULL default '',
	            UNIQUE (Uid)
	          )";
	pg_query($query);

	$query = "CREATE TABLE IF NOT EXISTS players (
	            Id serial primary key,
	            Login varvarchar(50) NOT NULL default '',
	            Game varvarchar(3) NOT NULL default '',
	            NickName varvarchar(100) NOT NULL default '',
	            Nation varvarchar(3) NOT NULL default '',
	            UpdatedAt timestamp without time zone default (now() at time zone 'utc'),
	            Wins int NOT NULL default 0,
	            TimePlayed int  NOT NULL default 0,
	            TeamName varvarchar(60) NOT NULL default '',
	            UNIQUE (Login)
	          )";
	pg_query($query);

	$query = "CREATE TABLE IF NOT EXISTS records (
	            Id serial primary key,
	            ChallengeId int NOT NULL default 0,
	            PlayerId int NOT NULL default 0,
	            Score int NOT NULL default 0,
	            Date timestamp without time zone default (now() at time zone 'utc'),
	            Checkpoints text NOT NULL,
	            UNIQUE (PlayerId,ChallengeId)
	          ) ";
	pg_query($query);

	$query = "CREATE TABLE IF NOT EXISTS players_extra (
	            playerID int NOT NULL default 0,
	            cps smallint NOT NULL default -1,
	            dedicps smallint NOT NULL default -1,
	            donations int NOT NULL default 0,
	            style varvarchar(20) NOT NULL default '',
	            panels varvarchar(255) NOT NULL default ''
	          )";
	pg_query($query);

	$aseco->console('[LocalDB] ...Structure OK!');
}  // ldb_connect

// called @ onEverySecond
function ldb_reconnect($aseco) {
	global $ldb_settings;

	// check if any players online
	if (empty($aseco->server->players->player_list)) {
		// check if PostgreSQL connection still alive
		if (!pg_ping($ldb_settings['mysql']['connection'])) {
			// connection timed out so reconnect
			pg_close($ldb_settings['mysql']['connection']);
			if (!$ldb_settings['mysql']['connection'] = pg_connect("host=localhost port=5432 dbname=aseco user=aseco password=")) {
				trigger_error('[LocalDB] Could not authenticate at PostgreSQL server!', E_USER_ERROR);
			}
			$aseco->console('[LocalDB] Reconnected to PostgreSQL Server');
		}
	}
}  // ldb_reconnect

// called @ onSync
function ldb_sync($aseco) {

/* ldb_playerConnect on sync already invoked via onPlayerConnect event,
   so disable it here - Xymph
	$aseco->console('[LocalDB] Synchronize players with database');

	// take each player in the list and simulate a join
	while ($player = $aseco->server->players->nextPlayer()) {
		// log debug message
		if ($aseco->debug) $aseco->console('[LocalDB] Sending player ' . $player->login);
		ldb_playerConnect($aseco, $player);
	}
disabled */

	// reset player list
	$aseco->server->players->resetPlayers();
}  // ldb_sync

// called @ onPlayerConnect
function ldb_playerConnect($aseco, $player) {
	global $ldb_settings;

	if ($aseco->server->getGame() == 'TMF')
		$nation = mapCountry($player->nation);
	else  // TMN/TMS/TMO
		$nation = $player->nation;

	// get player stats
	$query = 'SELECT Id, Wins, TimePlayed, TeamName FROM players
	          WHERE Login=' . quotedString($player->login); // .
	          // ' AND Game=' . quotedString($aseco->server->getGame());
	$result = pg_query($query);

	if ($result === false || pg_num_rows($result) === false) {
		if ($result !== false)
			pg_free_result($result);
		trigger_error('Could not get stats of connecting player! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return;
	}

	// was retrieved
	if (pg_num_rows($result) > 0) {
		$dbplayer = pg_fetch_object($result);
		pg_free_result($result);

		// update player stats
		$player->id = $dbplayer->Id;
		if ($player->teamname == '' && $dbplayer->TeamName != '') {
			$player->teamname = $dbplayer->TeamName;
		}
		if ($player->wins < $dbplayer->Wins) {
			$player->wins = $dbplayer->Wins;
		}
		if ($player->timeplayed < $dbplayer->TimePlayed) {
			$player->timeplayed = $dbplayer->TimePlayed;
		}

		// update player data
		$query = 'UPDATE players
		          SET NickName=' . quotedString($player->nickname) . ',
		              Nation=' . quotedString($nation) . ',
		              TeamName=' . quotedString($player->teamname) . ',
		              UpdatedAt=NOW()
		          WHERE Login=' . quotedString($player->login); // .
		          // ' AND Game=' . quotedString($aseco->server->getGame());
		$result = pg_query($query);

		if ($result === false || pg_affected_rows() == -1) {
			trigger_error('Could not update connecting player! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			return;
		}

	// could not be retrieved
	} else {  // pg_num_rows() == 0
		pg_free_result($result);
		$player->id = 0;

		// insert player
		$query = 'INSERT INTO players
		          (Login, Game, NickName, Nation, TeamName, UpdatedAt)
		          VALUES
		          (' . quotedString($player->login) . ', ' .
		           quotedString($aseco->server->getGame()) . ', ' .
		           quotedString($player->nickname) . ', ' .
		           quotedString($nation) . ', ' .
		           quotedString($player->teamname) . ', NOW())';
		$result = pg_query($query);

		if ($result === false || pg_affected_rows() != 1) {
			trigger_error('Could not insert connecting player! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			return;
		} else {
			$query = 'SELECT LAST_INSERT_ID() FROM players';
			$result = pg_query($query);
			if ($result === false || pg_num_rows($result) === false) {
				if ($result !== false)
					pg_free_result($result);
				trigger_error('Could not get inserted player\'s id! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
				return;
			} else {
				$dbplayer = pg_fetch_row($result);
				$player->id = $dbplayer[0];
				pg_free_result($result);
			}
		}
	}

	// check for player's extra data
	$query = 'SELECT playerID FROM players_extra
	          WHERE playerID=' . $player->id;
	$result = pg_query($query);

	if ($result === false || pg_num_rows($result) === false) {
		if ($result !== false)
			pg_free_result($result);
		trigger_error('Could not get player\'s extra data! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return;
	}

	// was retrieved
	if (pg_num_rows($result) > 0) {
		pg_free_result($result);

	// could not be retrieved
	} else {  // pg_num_rows() == 0
		pg_free_result($result);

		// insert player's default extra data
		$query = 'INSERT INTO players_extra
		          (playerID, cps, dedicps, donations, style, panels)
		          VALUES
		          (' . $player->id . ', ' .
		           ($aseco->settings['auto_enable_cps'] ? 0 : -1) . ', ' .
		           ($aseco->settings['auto_enable_dedicps'] ? 0 : -1) . ', 0, ' .
		           quotedString($aseco->settings['window_style']) . ', ' .
		           quotedString($aseco->settings['admin_panel'] . '/' .
		                        $aseco->settings['donate_panel'] . '/' .
		                        $aseco->settings['records_panel'] . '/' .
		                        $aseco->settings['vote_panel']) . ')';
		$result = pg_query($query);

		if ($result === false || pg_affected_rows() != 1) {
			trigger_error('Could not insert player\'s extra data! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		}
	}
}  // ldb_playerConnect

// called @ onPlayerDisconnect
function ldb_playerDisconnect($aseco, $player) {

	// ignore fluke disconnects with empty logins
	if ($player->login == '') return;

	// update player
	$query = 'UPDATE players
	          SET UpdatedAt=NOW(),
	              TimePlayed=TimePlayed+' . $player->getTimeOnline() . '
	          WHERE Login=' . quotedString($player->login); // .
	          // ' AND Game=' . quotedString($aseco->server->getGame());
	$result = pg_query($query);

	if ($result === false || pg_affected_rows() == -1) {
		trigger_error('Could not update disconnecting player! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_playerDisconnect

function ldb_getDonations($aseco, $login) {

	// get player's donations
	$query = 'SELECT donations FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_num_rows($result) === false || pg_num_rows($result) == 0) {
		if ($result !== false)
			pg_free_result($result);
		trigger_error('Could not get player\'s donations! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = pg_fetch_object($result);
		pg_free_result($result);

		return $dbextra->donations;
	}
}  // ldb_getDonations

function ldb_updateDonations($aseco, $login, $donation) {

	// update player's donations
	$query = 'UPDATE players_extra
	          SET donations=donations+' . $donation . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_affected_rows() != 1) {
		trigger_error('Could not update player\'s donations! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_updateDonations

function ldb_getCPs($aseco, $login) {

	// get player's CPs settings
	$query = 'SELECT cps, dedicps FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_num_rows($result) === false || pg_num_rows($result) == 0) {
		if ($result !== false)
			pg_free_result($result);
		trigger_error('Could not get player\'s CPs! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = pg_fetch_object($result);
		pg_free_result($result);

		return array('cps' => $dbextra->cps, 'dedicps' => $dbextra->dedicps);
	}
}  // ldb_getCPs

function ldb_setCPs($aseco, $login, $cps, $dedicps) {

	$query = 'UPDATE players_extra
	          SET cps=' . $cps . ', dedicps=' . $dedicps . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_affected_rows() == -1) {
		trigger_error('Could not update player\'s CPs! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_setCPs

function ldb_getStyle($aseco, $login) {

	// get player's style
	$query = 'SELECT style FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_num_rows($result) === false || pg_num_rows($result) == 0) {
		if ($result !== false)
			pg_free_result($result);
		trigger_error('Could not get player\'s style! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = pg_fetch_object($result);
		pg_free_result($result);

		return $dbextra->style;
	}
}  // ldb_getStyle

function ldb_setStyle($aseco, $login, $style) {

	$query = 'UPDATE players_extra
	          SET style=' . quotedString($style) . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_affected_rows() == -1) {
		trigger_error('Could not update player\'s style! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_setStyle

function ldb_getPanels($aseco, $login) {

	// get player's panels
	$query = 'SELECT panels FROM players_extra
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_num_rows($result) === false || pg_num_rows($result) == 0) {
		if ($result !== false)
			pg_free_result($result);
		trigger_error('Could not get player\'s panels! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return false;
	} else {
		$dbextra = pg_fetch_object($result);
		pg_free_result($result);

		$panel = explode('/', $dbextra->panels);
		$panels = array();
		$panels['admin'] = $panel[0];
		$panels['donate'] = $panel[1];
		$panels['records'] = $panel[2];
		$panels['vote'] = $panel[3];
		return $panels;
	}
}  // ldb_getPanels

function ldb_setPanel($aseco, $login, $type, $panel) {

	// update player's panels
	$panels = ldb_getPanels($aseco, $login);
	$panels[$type] = $panel;
	$query = 'UPDATE players_extra
	          SET panels=' . quotedString($panels['admin'] . '/' . $panels['donate'] . '/' .
	                                      $panels['records'] . '/' . $panels['vote']) . '
	          WHERE playerID=' . $aseco->getPlayerId($login);
	$result = pg_query($query);

	if ($result === false || pg_affected_rows() == -1) {
		trigger_error('Could not update player\'s panels! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_setPanel

// called @ onPlayerFinish
function ldb_playerFinish($aseco, $finish_item) {
	global $ldb_records, $ldb_settings,
	       $checkpoints;  // from plugin.checkpoints.php

	// if no actual finish, bail out immediately
	if ($finish_item->score == 0) return;

	// in Laps mode on real PlayerFinish event, bail out too
	if ($aseco->server->gameinfo->mode == Gameinfo::LAPS && !$finish_item->new) return;

	$login = $finish_item->player->login;
	$nickname = stripColors($finish_item->player->nickname);

	// reset lap 'Finish' flag & add checkpoints
	$finish_item->new = false;
	$finish_item->checks = (isset($checkpoints[$login]) ? $checkpoints[$login]->curr_cps : array());

	// drove a new record?
	// go through each of the XX records
	for ($i = 0; $i < $ldb_records->max; $i++) {
		$cur_record = $ldb_records->getRecord($i);

		// if player's time/score is better, or record isn't set (thanks eyez)
		if ($cur_record === false || ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
		                              $finish_item->score > $cur_record->score :
		                              $finish_item->score < $cur_record->score)) {

			// does player have a record already?
			$cur_rank = -1;
			$cur_score = 0;
			for ($rank = 0; $rank < $ldb_records->count(); $rank++) {
				$rec = $ldb_records->getRecord($rank);

				if ($rec->player->login == $login) {

					// new record worse than old one
					if ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
					    $finish_item->score < $rec->score :
					    $finish_item->score > $rec->score) {
						return;

					// new record is better than or equal to old one
					} else {
						$cur_rank = $rank;
						$cur_score = $rec->score;
						break;
					}
				}
			}

			$finish_time = $finish_item->score;
			if ($aseco->server->gameinfo->mode != Gameinfo::STNT)
				$finish_time = formatTime($finish_time);

			if ($cur_rank != -1) {  // player has a record in topXX already

				// compute difference to old record
				if ($aseco->server->gameinfo->mode != Gameinfo::STNT) {
					$diff = $cur_score - $finish_item->score;
					$sec = floor($diff/1000);
					$hun = ($diff - ($sec * 1000)) / 10;
				} else {  // Stunts
					$diff = $finish_item->score - $cur_score;
				}

				// update record if improved
				if ($diff > 0) {
					$finish_item->new = true;
					$ldb_records->setRecord($cur_rank, $finish_item);
				}

				// player moved up in LR list
				if ($cur_rank > $i) {

					// move record to the new position
					$ldb_records->moveRecord($cur_rank, $i);

					// do a player improved his/her LR rank message
					$message = formatText($ldb_settings['messages']['RECORD_NEW_RANK'][0],
					                      $nickname,
					                      $i+1,
					                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
					                      $finish_time,
					                      $cur_rank+1,
					                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
					                       '+' . $diff : sprintf('-%d.%02d', $sec, $hun)));

					// show chat message to all or player
					if ($ldb_settings['display']) {
						if ($i < $ldb_settings['limit']) {
							if ($aseco->settings['recs_in_window'] && function_exists('send_window_message'))
								send_window_message($aseco, $message, false);
							else
								$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
						} else {
							$message = str_replace('{#server}>> ', '{#server}> ', $message);
							$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
						}
					}

				} else {

					if ($diff == 0) {
						// do a player equaled his/her record message
						$message = formatText($ldb_settings['messages']['RECORD_EQUAL'][0],
						                      $nickname,
						                      $cur_rank+1,
						                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
						                      $finish_time);
					} else {
						// do a player secured his/her record message
						$message = formatText($ldb_settings['messages']['RECORD_NEW'][0],
						                      $nickname,
						                      $i+1,
						                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
						                      $finish_time,
						                      $cur_rank+1,
						                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
						                       '+' . $diff : sprintf('-%d.%02d', $sec, $hun)));
					}

					// show chat message to all or player
					if ($ldb_settings['display']) {
						if ($i < $ldb_settings['limit']) {
							if ($aseco->settings['recs_in_window'] && function_exists('send_window_message'))
								send_window_message($aseco, $message, false);
							else
								$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
						} else {
							$message = str_replace('{#server}>> ', '{#server}> ', $message);
							$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
						}
					}
				}

			} else {  // player hasn't got a record yet

				// if previously tracking own/last local record, now track new one
				if (isset($checkpoints[$login]) &&
				    $checkpoints[$login]->loclrec == 0 && $checkpoints[$login]->dedirec == -1) {
					$checkpoints[$login]->best_fin = $checkpoints[$login]->curr_fin;
					$checkpoints[$login]->best_cps = $checkpoints[$login]->curr_cps;
				}

				// insert new record at the specified position
				$finish_item->new = true;
				$ldb_records->addRecord($finish_item, $i);

				// do a player drove first record message
				$message = formatText($ldb_settings['messages']['RECORD_FIRST'][0],
				                      $nickname,
				                      $i+1,
				                      ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'Score' : 'Time'),
				                      $finish_time);

				// show chat message to all or player
				if ($ldb_settings['display']) {
					if ($i < $ldb_settings['limit']) {
						if ($aseco->settings['recs_in_window'] && function_exists('send_window_message'))
							send_window_message($aseco, $message, false);
						else
							$aseco->client->query('ChatSendServerMessage', $aseco->formatColors($message));
					} else {
						$message = str_replace('{#server}>> ', '{#server}> ', $message);
						$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					}
				}
			}

			// update aseco records
			$aseco->server->records = $ldb_records;

			// log records when debugging is set to true
			//if ($aseco->debug) $aseco->console('ldb_playerFinish records:' . CRLF . print_r($ldb_records, true));

			// insert and log a new local record (not an equalled one)
			if ($finish_item->new) {
				ldb_insert_record($finish_item);

				// update all panels if new #1 record
				if ($aseco->server->getGame() == 'TMF' && $i == 0) {
					setRecordsPanel('local', ($aseco->server->gameinfo->mode == Gameinfo::STNT ?
					                          str_pad($finish_item->score, 5, ' ', STR_PAD_LEFT) :
					                          formatTime($finish_item->score)));
					if (function_exists('update_allrecpanels'))
						update_allrecpanels($aseco, null);  // from plugin.panels.php
				}

				// log record message in console
				$aseco->console('[LocalDB] player {1} finished with {2} and took the {3}. LR place!',
				                $login, $finish_item->score, $i+1);

				// throw 'local record' event
				$finish_item->pos = $i+1;
				$aseco->releaseEvent('onLocalRecord', $finish_item);
			}

			// got the record, now stop!
			return;
		}
	}
}  // ldb_playerFinish

function ldb_insert_record($record) {
	global $aseco, $ldb_challenge;

	$playerid = $record->player->id;
	$cps = implode(',', $record->checks);

	// insert new record or update existing
	$query = 'INSERT INTO records
	          (ChallengeId, PlayerId, Score, Date, Checkpoints)
	          VALUES
	          (' . $ldb_challenge->id . ', ' . $playerid . ', ' .
	           $record->score . ', NOW(), ' . quotedString($cps) . ') ' .
	         'ON DUPLICATE KEY UPDATE ' .
	          'Score=VALUES(Score), Date=VALUES(Date), Checkpoints=VALUES(Checkpoints)';
	$result = pg_query($query);

	if ($result === false || pg_affected_rows() <= 0) {
		trigger_error('Could not insert/update record! (' . pg_last_error() . ': ' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_insert_record

function ldb_removeRecord($aseco, $cid, $pid, $recno) {
	global $ldb_records;

	// remove record
	$query = 'DELETE FROM records WHERE ChallengeId=' . $cid . ' AND PlayerId=' . $pid;
	$result = pg_query($query);
	if ($result === false || pg_affected_rows() != 1) {
		trigger_error('Could not remove record! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}

	// remove record from specified position
	$ldb_records->delRecord($recno);

	// check if fill up is needed
	if ($ldb_records->count() == ($ldb_records->max - 1)) {
		// get max'th time
		$query = 'SELECT DISTINCT playerid,score FROM rs_times t1 WHERE challengeid=' . $cid .
		         ' AND score=(SELECT MIN(t2.score) FROM rs_times t2 WHERE challengeid=' . $cid .
		         '            AND t1.playerid=t2.playerid) ORDER BY score,date LIMIT ' . ($ldb_records->max - 1) . ',1';
		$result = pg_query($query);

		if ($result !== false && pg_num_rows($result) == 1) {
			$timerow = pg_fetch_object($result);

			// get corresponding date/time & checkpoints
			$query = 'SELECT date,checkpoints FROM rs_times WHERE challengeid=' . $cid .
			         ' AND playerid=' . $timerow->playerid . ' ORDER BY score,date LIMIT 1';
			$result2 = pg_query($query);
			$timerow2 = pg_fetch_object($result2);
			$datetime = date('Y-m-d H:i:s', $timerow2->date);
			pg_free_result($result2);

			// insert/update new max'th record
			$query = 'INSERT INTO records
			          (ChallengeId, PlayerId, Score, Date, Checkpoints)
			          VALUES
			          (' . $cid . ', ' . $timerow->playerid . ', ' .
			           $timerow->score . ', ' . quotedString($datetime) . ', ' .
			           quotedString($timerow2->checkpoints) . ') ' .
			         'ON DUPLICATE KEY UPDATE ' .
			          'Score=VALUES(Score), Date=VALUES(Date), Checkpoints=VALUES(Checkpoints)';
			$result2 = pg_query($query);

			if ($result2 === false || pg_affected_rows() <= 0) {
				trigger_error('Could not insert/update record! (' . pg_last_error() . ': ' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			}

			// get player info
			$query = 'SELECT * FROM players WHERE id=' . $timerow->playerid;
			$result2 = pg_query($query);
			$playrow = mysql_fetch_array($result2);
			pg_free_result($result2);

			// create record object
			$record_item = new Record();
			$record_item->score = $timerow->score;
			$record_item->checks = ($timerow2->checkpoints != '' ? explode(',', $timerow2->checkpoints) : array());
			$record_item->new = false;

			// create a player object to put it into the record object
			$player_item = new Player();
			$player_item->nickname = $playrow['NickName'];
			$player_item->login = $playrow['Login'];
			$record_item->player = $player_item;

			// add the track information to the record object
			$record_item->challenge = clone $aseco->server->challenge;
			unset($record_item->challenge->gbx);  // reduce memory usage
			unset($record_item->challenge->tmx);

			// add the created record to the list
			$ldb_records->addRecord($record_item);
		}
		if ($result !== false)
			pg_free_result($result);
	}

	// update aseco records
	$aseco->server->records = $ldb_records;
}  // ldb_remove_record

// called @ onNewChallenge
function ldb_newChallenge($aseco, $challenge) {
	global $ldb_challenge, $ldb_records, $ldb_settings;

	$ldb_records->clear();
	$aseco->server->records->clear();

	// on relay, ignore master server's challenge
	if ($aseco->server->isrelay) {
		$challenge->id = 0;
		return;
	}

	$order = ($aseco->server->gameinfo->mode == Gameinfo::STNT ? 'DESC' : 'ASC');
	$query = 'SELECT c.Id AS ChallengeId, r.Score, p.NickName, p.Login, r.Date, r.Checkpoints
	          FROM challenges c
	          LEFT JOIN records r ON (r.ChallengeId=c.Id)
	          LEFT JOIN players p ON (r.PlayerId=p.Id)
	          WHERE c.Uid=' . quotedString($challenge->uid) . '
	          GROUP BY r.Id
	          ORDER BY r.Score ' . $order . ',r.Date ASC
	          LIMIT ' . $ldb_records->max;
	$result = pg_query($query);

	if ($result === false || pg_num_rows($result) === false) {
		if ($result !== false)
			pg_free_result($result);
		trigger_error('Could not get challenge info! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		return;
	}

	// challenge found?
	if (pg_num_rows($result) > 0) {

		// get each record
		while ($record = mysql_fetch_array($result)) {

			// create record object
			$record_item = new Record();
			$record_item->score = $record['Score'];
			$record_item->checks = ($record['Checkpoints'] != '' ? explode(',', $record['Checkpoints']) : array());
			$record_item->new = false;

			// create a player object to put it into the record object
			$player_item = new Player();
			$player_item->nickname = $record['NickName'];
			$player_item->login = $record['Login'];
			$record_item->player = $player_item;

			// add the track information to the record object
			$record_item->challenge = clone $challenge;
			unset($record_item->challenge->gbx);  // reduce memory usage
			unset($record_item->challenge->tmx);

			// add the created record to the list
			$ldb_records->addRecord($record_item);

			// get challenge info
			$ldb_challenge->id = $record['ChallengeId'];
			$challenge->id = $record['ChallengeId'];
		}

		// update aseco records
		$aseco->server->records = $ldb_records;

		// log records when debugging is set to true
		//if ($aseco->debug) $aseco->console('ldb_newChallenge records:' . CRLF . print_r($ldb_records, true));

		pg_free_result($result);

	// challenge isn't in database yet
	} else {
		pg_free_result($result);

		// then create it
		$query = 'INSERT INTO challenges
		          (Uid, Name, Author, Environment)
		          VALUES
		          (' . quotedString($challenge->uid) . ', ' .
		           quotedString($challenge->name) . ', ' .
		           quotedString($challenge->author) . ', ' .
		           quotedString($challenge->environment) . ')';
		$result = pg_query($query);

		// challenge was inserted successfully
		if ($result !== false && pg_affected_rows() == 1) {
			// get its Id now
			$query = 'SELECT Id FROM challenges
			          WHERE Uid=' . quotedString($challenge->uid);
			$result = pg_query($query);

			if ($result !== false && pg_num_rows($result) == 1) {
				$row = pg_fetch_row($result);
				$ldb_challenge->id = $row[0];
				$challenge->id = $row[0];
			} else {
				// challenge Id could not be found
				trigger_error('Could not get new challenge id! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
			}
			if ($result !== false)
				pg_free_result($result);
		} else {
			trigger_error('Could not insert new challenge! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
		}
	}
}  // ldb_newChallenge

// called @ onPlayerWins
function ldb_playerWins($aseco, $player) {

	$wins = $player->getWins();
	$query = 'UPDATE players
	          SET Wins=' . $wins . '
	          WHERE Login=' . quotedString($player->login);
	$result = pg_query($query);

	if ($result === false || pg_affected_rows() != 1) {
		trigger_error('Could not update winning player! (' . pg_last_error() . ')' . CRLF . 'sql = ' . $query, E_USER_WARNING);
	}
}  // ldb_playerWins
?>
