<?php
class Pokemon extends CI_Model{

	// --------------------------------
	//   Funciones de usuario
	// --------------------------------

	function user($user){
		$query = $this->db
			->group_start()
				->where('telegramid', $user)
				->or_where('telegramuser', $user)
				->or_where('username', $user)
			->group_end()
			->where('anonymous', FALSE)
		->get('user');
		return ($query->num_rows() == 1 ? $query->row() : NULL);
	}

	function user_verified($user){
		$query = $this->db
			->select('verified')
			->where('telegramid', $user)
		->get('user');
		return ($query->num_rows() == 1 ? (bool) $query->row()->verified : FALSE);
	}

	function user_blocked($user){
		$query = $this->db
			->select('blocked')
			->where('telegramid', $user)
		->get('user');
		return ($query->num_rows() == 1 ? (bool) $query->row()->blocked : FALSE);
	}

	function user_exists($data, $retid = FALSE){
		$query = $this->db
			->group_start()
				->where('telegramid', $data)
				// ->or_where('telegramuser', $data) FIXME CONFLICTO con Username normal para registro
				->or_where('username', $data)
				->or_where('email', $data)
			->group_end()
			->where('anonymous', FALSE)
			->limit(1)
		->get('user');
		if($query->num_rows() == 1){
			return ($retid == TRUE ? $query->row()->telegramid : TRUE);
		}
		return FALSE;
	}

	function get_users($team = TRUE, $alldata = FALSE){
		if($team !== TRUE){
			$this->db->where_in('team', $team);
		}

		$query = $this->db->get('user');
		if($query->num_rows() > 0){
			if($alldata){
				return $query->result_array();
			}else{
				return array_column($query->result_array(), 'telegramid');
			}
		}
	}

	function find_users($array){
		$query = $this->db
			->where_in('username', $array)
			->or_where_in('telegramid', $array)
			->or_where_in('telegramuser', $array)
		->get('user');

		return ($query->num_rows() > 0 ? $query->result_array() : array());
	}

	function user_flags($user, $flag = NULL, $set = NULL){
		if(!$this->user_exists($user)){ return FALSE; }
		if($flag != NULL && is_bool($set)){
			if($set == TRUE){
				$q = $this->user_flags($user, $flag, NULL);
				if($q == FALSE){
					return $this->db
						->set('user', $user)
						->set('value', $flag)
					->insert('user_flags');
				}
			}else{
				if(!is_array($flag)){ $flag = [$flag]; }
				return $this->db
					->where('user', $user)
					->where_in('value', $flag)
				->delete('user_flags');
			}
		}else{
			if($flag != NULL && !is_array($flag)){ $flag = [$flag]; }
			if(is_array($flag)){ $this->db->where_in('value', $flag); }

			$query = $this->db
				->where('user', $user)
			->get('user_flags');

			if($flag == NULL && $query->num_rows() == 1){
				return array($query->row()->value);
			}elseif(count($flag) == 1 && $query->num_rows() == 1){
				return TRUE;
			}elseif($query->num_rows() > 0){
				return array_column($query->result_array(), 'value');
			}else{
				return FALSE;
			}
		}
	}

	function verify_user($validator, $target){
		$validator = $this->user($validator);
		$target = $this->user($target);

		if(empty($validator) or empty($target)){ return FALSE; }
		if(!$validator->verified or $validator->blocked and !$validator->authorized){ return FALSE; }
		$this->update_user_data($target->telegramid, 'verified', TRUE);
		$this->log($validator->telegramid, 'verify', $target->telegramid);

		return TRUE;
	}

	function team($user){
		$query = $this->db
			->select('team')
			->or_where('telegramid', $user)
			->or_where('username', $user)
			->or_where('email', $user)
		->get('user');
		return ($query->num_rows() == 1 ? $query->row()->team : FALSE);
	}

	function team_text($text){
		$equipos = [
			'Y' => ['amarillo', 'yellow', 'instinto'],
			'R' => ['rojo', 'red', 'valor'],
			'B' => ['azul', 'blue', 'sabidurí­a', 'sabiduria']
		];

		$text = strtolower($text);

		foreach($equipos as $k => $t){ if(in_array($text, $t)){ $text = $k; break; } }

		if(strlen($text) != 1){ return FALSE; }
		return $text;
	}

	function log($user, $action, $target = NULL){
		$this->db
			->set('user', $user)
			->set('target', $target)
			->set('action', $action)
		->insert('logs');

		return $this->db->insert_id();
	}

	function register($telegramid, $team){
		$team = $this->team_text($team);
		if($team === FALSE){ return FALSE; }

		if($this->user_exists($telegramid)){ return FALSE; }

		$this->db
			->set('telegramid', $telegramid)
			->set('team', $team)
			->set('verified', FALSE)
			->set('register_date', date("Y-m-d H:i:s"))
		->insert('user');
		return $this->db->insert_id();
	}

	function step($user, $step = FALSE){
		if($step === FALSE){
			// GET
			$query = $this->db
				->select('step')
				->where('telegramid', $user)
			->get('user');
			return ($query->num_rows() == 1 ? $query->row()->step : NULL);
		}else{
			// SET
			if(!empty($step)){ $step = strtoupper($step); }
			$query = $this->db
				->set('step', $step)
				->where('telegramid', $user)
			->update('user');
			return $this;
		}
	}

	function settings($user, $key, $value = NULL){
		$full = FALSE;
		if(strtolower($value) == "true"){ $value = TRUE; }
		if(strtolower($value) == "false"){ $value = FALSE; }
		if(strtolower($value) == "null"){ $value = NULL; }
		if(strtolower($value) == "fullinfo"){ $value = NULL; $full = TRUE; }
		if($value === NULL){
			if(is_array($key)){
                $this->db->where_in('type', $key);
            }elseif(in_array($key, ["all", "*"])){
                // NADA. Coje todo lo del UID.
            }else{
                $this->db
                    ->where('type', $key)
                    ->limit(1); // Solo un resultado, seguridad
            }
			$query = $this->db
				->where('uid', $user)
			->get('settings');
            if($query->num_rows() > 1){
				if($full){ return $query->result_array(); }
                return array_column($query->result_array(), 'value', 'type');
            }
			elseif($query->num_rows() == 1){ return ($full ? $query->row() : $query->row()->value); }
			return NULL;
		}else{
			if($this->settings($user, $key) === NULL){
				// INSERT
				$data = [
					'uid' => $user,
					'type' => $key,
					'value' => $value
				];
				$query = $this->db->insert('settings', $data);
				return $this->db->insert_id();
			}elseif(strtoupper($value) == "DELETE"){
                // DELETE
                return $this->db
                    ->where('uid', $user)
                    ->where('type', $key)
                ->delete('settings');
			}else{
                // UPDATE
                return $this->db
                    ->where('uid', $user)
                    ->where('type', $key)
                    ->set('value', $value)
                ->update('settings');
            }
		}
	}

	function update_user_data($telegram, $key, $value){
		return $this->db
			->set($key, $value)
			->where('telegramid', $telegram)
		->update('user');
	}

	// --------------------------------
	//   Funciones de Grupos
	// --------------------------------

	function group($id){
		$query = $this->db
			->where('id', $id)
			->limit(1)
		->get('chats');
		if($query->num_rows() == 1){ return $query->row(); }
	}

	function get_groups($shownames = FALSE){
		$query = $this->db
			->where_in('type', ['group', 'supergroup'])
			->where('active', TRUE)
			->order_by('last_date', 'DESC')
		->get('chats');
		if($query->num_rows() > 0){
			if($shownames){
				return array_column($query->result_array(), 'title', 'id');
			}else{
				return array_column($query->result_array(), 'id');
			}
		}
	}

	function group_disable($id, $stat = TRUE){
		$query = $this->db
			->where('id', $id)
			->set('active', !$stat)
		->update('chats');
		return $query;
	}

	// --------------------------------
	//   Funciones de Datos Pokemon
	// --------------------------------

	function find($search){
		$query = $this->db
			->where('id', $search)
			->or_where('name', $search)
		->get('pokedex');
		if($query->num_rows() == 1){ return $query->row_array(); }
		return FALSE;
	}

	function pokedex($pokemon = NULL){
		if(!empty($pokemon)){
			if(!is_array($pokemon)){ $pokemon = [$pokemon]; }
			$this->db
				->where_in('id', $pokemon)
				->or_where_in('name', $pokemon);
		}
		$query = $this->db->get('pokedex');
		if($query->num_rows() == 1){ return $query->row(); }
		if($query->num_rows() > 1){
			$pokedex = array();
			foreach($query->result_array() as $pk){
				$pokedex[$pk['id']] = (object) $pk;
			}
			return $pokedex;
		}
	}

	function evolution($search, $retval = TRUE){
		if(!is_array($search)){ $search = [$search]; }
		$query = $this->db
			->where_in('id', $search)
			->or_where_in('evolved_from', $search)
			->or_where_in('evolves_to', $search)
			->order_by('id', 'ASC')
		->get('pokedex');
		if($query->num_rows() > count($search)){
			$pks = array_column($query->result_array(), 'id');
			return $this->evolution($pks, $retval);
		}else{
			// CASO del Eevee, CUIDADO!
			foreach($query->result_array() as $p){ $pks[$p['id']] = $p; }
			$full = array();
			$full = $pks;
			foreach($pks as $p){
				if($p['evolves_to'] != NULL){ $full[$p['id']]['evolves_to'] = $pks[$p['evolves_to']]; }
				if($p['evolved_from'] != NULL){ $full[$p['id']]['evolved_from'] = $pks[$p['evolved_from']]; }
			}
			return $full;
		}
	}

	function misspell($text){
		$orig = $text;
		if(!is_array($text)){ $text = explode(" ", $text); }
		$pokedex = $this->pokedex();
		$query = $this->db
			->select(['LOWER(word) AS word', 'pokedex.name'])
			->from('pokemon_misspell')
			->join('pokedex', 'pokemon_misspell.pokemon = pokedex.id')
			->where_in('word', $text)
		->get();
		if($query->num_rows() == 0){ return $orig; }
		$rep = array_column($query->result_array(), 'word', 'name');
		foreach($text as $k => $w){
			$q = array_search(strtolower($w), $rep);
			if($q !== FALSE){ $this->misspell_count($w); $text[$k] = $q; }
		}
		return implode(" ", $text);
	}

	function misspell_count($word, $return = FALSE){
		if($return){
			$query = $this->db
				->where('word', $word)
				->or_where('id', $word)
			->get('pokemon_misspell');
			if($query->num_rows() == 1){ return $query->row()->visits; }
			return 0;
		}
		return $this->db
			->set('visits', 'visits+1', FALSE)
			->where('word', $word)
			->or_where('id', $word)
		->update('pokemon_misspell');
	}

	function attack_types(){
		$query = $this->db->get('pokedex_types');
		return ($query->num_rows() > 0 ? array_column($query->result_array(), 'name_es', 'id') : array());
	}

	function attack_type($search = NULL){
		if($search !== NULL){
			$this->db->where('id', $search)
			->or_where('type', $search)
			->or_where('name_es', $search);
		}
		$query = $this->db->get('pokedex_types');
		if($query->num_rows() > 0){
			if($query->num_rows() == 1){ return $query->row_array(); }
			return $query->result_array();
		}else{
			return FALSE;
		}
	}

	function attack_table($attackid){
		$query = $this->db
			->where('source', $attackid)
			->or_where('target', $attackid)
		->get('pokedex_attack');
		if($query->num_rows() > 0){ return $query->result_array(); }
		return NULL;
	}

	function level($level = NULL){
		if(!empty($level)){ $this->db->where('level', $level); }
		$query = $this->db->get('pokemon_level');

		if($query->num_rows() == 1){ return $query->row(); }
		if($query->num_rows() > 1){
			$levels = array();
			foreach($query->result_array() as $lv){
				$levels[$lv['level']] = (object) $lv;
			}
			return $levels;
		}
		return NULL;
	}

	function stardust($stardust, $powered = FALSE){
		if(!is_array($stardust)){ $stardust = [$stardust]; }
		if($powered == FALSE){ $this->db->like('level', '.0'); } // Si no se ha mejorado, son niveles enteros.
		$query = $this->db
			->where_in('stardust', $stardust)
			->order_by('level')
		->get('pokemon_level');
		if($query->num_rows() > 0){ return array_column($query->result_array(), 'level'); }
		return array();
	}

	function skill($find = NULL, $type = NULL){
		if(!empty($find)){
			$this->db
				->where('name', $find)
				->or_where('name_es', $find);
		}
		if(!empty($type)){ $this->db->where('type', $type); }
		$query = $this->db->get('pokemon_skills');
		if($query->num_rows() == 1){ return $query->row(); }
		if($query->num_rows() > 1){
			$skills = array();
			foreach($query->result_array() as $sk){
				$skills[$sk['id']] = (object) $sk;
			}
			return $skills;
		}
	}

	function skill_learn($pokemon){
		$query = $this->db
			->select('*')
			->from('pokemon_skills')
			->join('pokemon_skills_learn', 'pokemon_skills.id = pokemon_skills_learn.sid')
			->where('pokemon_skills_learn.pid', $pokemon)
		->get();
		if($query->num_rows() > 0){
			$skills = array();
			foreach($query->result_array() as $sk){
				$skills[$sk['id']] = (object) $sk;
			}
			return $skills;
		}
	}

	function trainer_rewards($find){
		if(is_numeric($find)){ $this->db->where('lvl', $find); }
		elseif(is_string($find)){ $this->db->where('item', $find); }
		$query = $this->db->get('trainer_rewards');

		if($query->num_rows() > 0){ return $query->result_array(); }
		return array();
	}

	function items($find = NULL){
		if(!empty($find)){ $this->db->where('name', $find)->or_where('display', $find); }
		$query = $this->db->get('items');

		if($query->num_rows() > 0){ return array_column($query->result_array(), 'display', 'name'); }
		return array();
	}

	// --------------------------------
	//   Funciones de Ubicación Pokemon
	// --------------------------------

	function add_found($poke, $user, $lat, $lng){
		$data = [
			'pokemon' => $poke,
			'user' => $user,
			'lat' => $lat,
			'lng' => $lng,
			'register_date' => date("Y-m-d H:i:s"),
			'points' => 0,
		];
		$this->db->insert('pokemon_spawns', $data);
		return $this->db->insert_id();
	}

	function location_distance($locA, $locB, $locC = NULL, $locD = NULL){
		$earth = 6371000;
		if($locC !== NULL && $locD !== NULL){
			$locA = [$locA, $locB];
			$locB = [$locC, $locD];
		}
		$locA[0] = deg2rad($locA[0]);
		$locA[1] = deg2rad($locA[1]);
		$locB[0] = deg2rad($locB[0]);
		$locB[1] = deg2rad($locB[1]);

		$latD = $locB[0] - $locA[0];
		$lonD = $locB[1] - $locA[1];

		$angle = 2 * asin(sqrt(pow(sin($latD / 2), 2) + cos($locA[0]) * cos($locB[0]) * pow(sin($lonD / 2), 2)));
		return ($angle * $earth);
	}

	function location_add($locA, $locB, $amount = NULL, $direction = NULL){
		// if(is_object($locA)){ $locA = [$locA->latitude, $locA->longitude]; }
		if(!is_array($locA) && $direction === NULL){ return FALSE; }
		if(!is_array($locA)){ $locA = [$locA, $locB]; }
		// si se rellenan 3 y direction es NULL, entonces locA es array.
		if(is_numeric($locB) && $amount !== NULL && $direction === NULL){
			$direction = $amount;
			$amount = $locB;
		}
		$direction = strtoupper($direction);
		$steps = [
			'N' => ['NORTE', 'NORTH', 'N', 'UP'],
			'NW' => ['NOROESTE', 'NORTHWEST', 'NW', 'UP_LEFT'],
			'NE' => ['NORESTE', 'NORTHEAST', 'NE', 'UP_RIGHT'],
			'S' => ['SUD', 'SOUTH', 'S', 'DOWN'],
			'SW' => ['SUDOESTE', 'SOUTHWEST', 'SW', 'DOWN_LEFT'],
			'SE' => ['SUDESTE', 'SOUTHEAST', 'SE', 'DOWN_RIGHT'],
			'W' => ['OESTE', 'WEST', 'W', 'O', 'LEFT'],
			'E' => ['ESTE', 'EAST', 'E', 'RIGHT']
		];
		foreach($steps as $s => $k){ if(in_array($direction, $k)){ $direction = $s; break; } } // Buscar y asociar dirección
		$earth = (40075 / 360 * 1000);

		if($direction == 'N'){ $locA[0] = $locA[0] + ($amount / $earth); }
		elseif($direction == 'S'){ $locA[0] = $locA[0] - ($amount / $earth); }
		elseif($direction == 'W'){ $locA[1] = $locA[1] - ($amount / $earth); }
		elseif($direction == 'E'){ $locA[1] = $locA[1] + ($amount / $earth); }
		elseif($direction == 'NW'){
			$locA[0] = $locA[0] + ($amount / $earth); // N
			$locA[1] = $locA[1] - ($amount / $earth); // W
		}elseif($direction == 'NE'){
			$locA[0] = $locA[0] + ($amount / $earth); // N
			$locA[1] = $locA[1] + ($amount / $earth); // E
		}elseif($direction == 'SW'){
			$locA[0] = $locA[0] - ($amount / $earth); // S
			$locA[1] = $locA[1] - ($amount / $earth); // W
		}elseif($direction == 'SE'){
			$locA[0] = $locA[0] - ($amount / $earth); // S
			$locA[1] = $locA[1] + ($amount / $earth); // E
		}

		return $locA;
	}

	function pokecrew($location, $radius = 3000, $limit = 10, $pokemon = NULL){
		$n = ($radius / 3);
		$rhor = ($n * 2);
		$rver = ($n * 1);
		$locNE = $this->location_add($location, $rhor, 'RIGHT');
		$locNE = $this->location_add($locNE, $rver, 'UP');
		$locSW = $this->location_add($location, $rhor, 'LEFT');
		$locSW = $this->location_add($locSW, $rver, 'DOWN');

		$data = [
			'center_latitude' => $location[0],
			'center_longitude' => $location[1],
			'live' => 'false',
			'minimal' => 'true',
			'northeast_latitude' => $locNE[0],
			'northeast_longitude' => $locNE[1],
			'pokemon_id' => $pokemon,
			'southwest_latitude' => $locSW[0],
			'southwest_longitude' => $locSW[1],
		];
		$url = "https://api.pokecrew.com/api/v1/seens";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, ($url ."?" .http_build_query($data)) );
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$json = curl_exec($ch);
		curl_close($ch);
		// $json = file_get_contents($url ."?" .http_build_query($data));
		$json = json_decode($json, TRUE);
		if(count($json['seens']) == 0){ return array(); }
		$seens = array(); // Lista completa
		$pokes = array(); // ID de Pokemon (para evitar duplicados)
		foreach($json['seens'] as $pk){
			if(in_array($pk['pokemon_id'], $pokes)){ continue; } // Un Pokemon por ubicación
			if(count($seens) >= $limit){ break; } // Limitar
			if(!empty($pokemon) && $pokemon != $pk['pokemon_id']){ continue; }
			if(!empty($pokemon) && count($seens) == 1){ break; } // HACK Están ordenados por más reciente, asi que me quedo sólo con el primero.
			$locpk = [$pk['latitude'], $pk['longitude']];
			$seens[] = [
				'id' => $pk['id'],
				'lat' => $pk['latitude'],
				'lng' => $pk['longitude'],
				'pokemon' => $pk['pokemon_id'],
				'last_seen' => $pk['expires_at'],
				'points' => ($pk['upvote_count'] - $pk['downvote_count'] - 1),
				'distance' => $this->location_distance($location, $locpk),
			];
		}
		return $seens;
	}

	function spawn_near($location, $radius = 500, $limit = 10, $pokemon = NULL){
		if(!is_array($location) or count($location) != 2){ return FALSE; }
		$sql_dist = "ASIN(SQRT(POW(SIN((RADIANS($location[0]) - RADIANS(lat)) / 2), 2) + COS(RADIANS(lat)) * COS(RADIANS($location[0])) * "
					."POW(SIN((RADIANS($location[1]) - RADIANS(lng)) / 2), 2) )) * 2 * 6371000";
		$query = $this->db
			->select(['*', "$sql_dist AS distance"])
			->where("($sql_dist) <=", $radius)
			->where_in('pokemon', $pokemon)
			->limit($limit)
			->order_by($sql_dist, 'ASC', FALSE)
			// ->order_by('last_seen', 'DESC')
			->order_by('id', 'DESC')
			->group_by('pokemon')
		->get('pokemon_spawns');
		return ($query->num_rows() > 0 ? $query->result_array() : array());
	}

	// --------------------------------
	//   Funciones de información general
	// --------------------------------

	function link($text, $count = FALSE, $table = 'links'){
		if(strtolower($text) != "all" && $text !== TRUE){
			$this->db
				->where('name', $text)
				->or_where('id', $text);
		}
		$query = $this->db->get($table);

		if($query->num_rows() > 1){
			if($count === FALSE){ return $query->result_array(); }
			else{ return $query->num_rows(); }
		}elseif($query->num_rows() == 1){
			$link = $query->row();
			$this->db
				->set('visits', 'visits+1', FALSE)
				->where('id', $link->id)
			->update($table);
			if($table == "meanings"){ return $link->text; }
			if($table == "public_groups"){ return $link->link; }
			return $link->url;
		}
	}

	function meaning($text, $count = FALSE){
		return $this->link($text, $count, 'meanings');
	}

	function group_link($text, $count = FALSE){
		return $this->link($text, $count, 'public_groups');
	}

	function joke($full = FALSE, $random = TRUE, $type = NULL){
		if(is_numeric($random)){ $this->db->where('id', $random); }
		if($type !== NULL){
			if(!is_array($type)){ $type = [$type]; }
			$this->db->where_in('type', $type);
		}
		$query = $this->db
			->limit(1)
			->order_by('RAND()')
		->get('jokes');
		if($query->num_rows() == 0){ return NULL; }
		$ret = $query->row();
		$this->db
			->where('id', $ret->id)
			->set('visits', 'visits+1', FALSE)
		->update('jokes');
		return ($full ? $ret : $ret->joke);
	}

    function count_teams(){
        $query = $this->db
            ->select(['team', 'count(*) AS count'])
            ->where_in('team', ['R','B','Y'])
            ->group_by('team')
        ->get('user');
		return ($query->num_rows() > 0 ? array_column($query->result_array(), 'count', 'team') : array());
    }

} ?>
