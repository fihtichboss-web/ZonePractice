<?php

declare(strict_types=1);

namespace ZonePractice;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Armor;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;

class Duel {
	public bool $started = false;
	public bool $ended = false;
	/** @var string[] */
	public array $alive = [];

	/**
	 * @param string[] $teamA
	 * @param string[] $teamB
	 */
	public function __construct(
		public string $mode,
		public string $arena,
		public array $teamA,
		public array $teamB
	) {
		$this->alive = array_merge($teamA, $teamB);
	}

	public function teamOf(string $name): ?string {
		if (in_array($name, $this->teamA, true)) {
			return "a";
		}
		if (in_array($name, $this->teamB, true)) {
			return "b";
		}
		return null;
	}
}

class Main extends PluginBase implements Listener {

	/** @var array<string,string> lobby | queue | ffa | duel */
	private array $state = [];
	/** @var array<string,string[]> */
	private array $queues = [];
	/** @var array<string,Duel> */
	private array $inDuel = [];
	/** @var array<string,bool> */
	private array $busyArenas = [];

	protected function onEnable(): void {
		$this->saveDefaultConfig();
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	// ================= ВСПОМОГАТЕЛЬНОЕ =================

	private function pos(array $c): ?Position {
		$wm = $this->getServer()->getWorldManager();
		$name = (string) ($c["world"] ?? "");
		$world = $wm->getWorldByName($name);
		if ($world === null) {
			$wm->loadWorld($name);
			$world = $wm->getWorldByName($name);
		}
		if ($world === null) {
			return null;
		}
		return new Position((float) ($c["x"] ?? 0), (float) ($c["y"] ?? 100), (float) ($c["z"] ?? 0), $world);
	}

	private function arenaPos(string $arena, int $idx): ?Position {
		$a = $this->getConfig()->get("arenas", [])[$arena] ?? null;
		if (!is_array($a) || !isset($a["p" . $idx]) || !is_array($a["p" . $idx])) {
			return null;
		}
		$p = $a["p" . $idx];
		$p["world"] = $a["world"] ?? "world";
		return $this->pos($p);
	}

	private function stateOf(Player $p): string {
		return $this->state[$p->getName()] ?? "lobby";
	}

	private function giveKit(Player $p, string $kit): void {
		$p->setGamemode(GameMode::SURVIVAL());
		$p->getInventory()->clearAll();
		$p->getArmorInventory()->clearAll();
		$p->getEffects()->clear();
		$p->setHealth($p->getMaxHealth());
		$p->getHungerManager()->setFood(20.0);

		$cfg = $this->getConfig()->getNested("kits." . $kit);
		if (!is_array($cfg)) {
			return;
		}
		$parser = StringToItemParser::getInstance();

		foreach (($cfg["armor"] ?? []) as $name) {
			$item = $parser->parse((string) $name);
			if ($item instanceof Armor) {
				$p->getArmorInventory()->setItem($item->getArmorSlot(), $item);
			} else {
				$this->getLogger()->warning("Не найдена броня в ките $kit: $name");
			}
		}
		foreach (($cfg["items"] ?? []) as $entry) {
			$parts = explode(":", (string) $entry, 2);
			$item = $parser->parse($parts[0]);
			if ($item === null) {
				$this->getLogger()->warning("Не найден предмет в ките $kit: {$parts[0]}");
				continue;
			}
			$item->setCount(max(1, (int) ($parts[1] ?? 1)));
			$p->getInventory()->addItem($item);
		}
		foreach (($cfg["effects"] ?? []) as $entry) {
			$parts = explode(":", (string) $entry, 2);
			$eff = StringToEffectParser::getInstance()->parse($parts[0]);
			if ($eff === null) {
				$this->getLogger()->warning("Не найден эффект в ките $kit: {$parts[0]}");
				continue;
			}
			$p->getEffects()->add(new EffectInstance($eff, 20 * 3600, (int) ($parts[1] ?? 0)));
		}
	}

	private function toLobby(Player $p): void {
		$name = $p->getName();
		unset($this->inDuel[$name]);
		$this->removeFromQueue($name);
		$this->state[$name] = "lobby";
		$p->setNoClientPredictions(false);
		$p->getInventory()->clearAll();
		$p->getArmorInventory()->clearAll();
		$p->getEffects()->clear();
		$p->setHealth($p->getMaxHealth());
		$p->getHungerManager()->setFood(20.0);
		$pos = $this->pos($this->getConfig()->get("lobby", []));
		if ($pos !== null) {
			$p->teleport($pos);
		}
	}

	private function removeFromQueue(string $name): void {
		foreach ($this->queues as $mode => $list) {
			$this->queues[$mode] = array_values(array_diff($list, [$name]));
		}
	}

	// ================= FFA =================

	private function enterFfa(Player $p): void {
		$pos = $this->pos($this->getConfig()->get("ffa", []));
		if ($pos === null) {
			$p->sendMessage("§cFFA не настроено. Админ: /zp setffa");
			return;
		}
		$this->removeFromQueue($p->getName());
		$this->state[$p->getName()] = "ffa";
		$p->setNoClientPredictions(false);
		$this->giveKit($p, "ffa");
		$p->teleport($pos);
	}

	// ================= ДУЭЛИ =================

	private function joinQueue(Player $p, string $mode): void {
		$modes = $this->getConfig()->get("modes", []);
		if (!isset($modes[$mode])) {
			$p->sendMessage("§cРежимы: " . implode(", ", array_keys($modes)));
			return;
		}
		if ($this->stateOf($p) !== "lobby") {
			$p->sendMessage("§cСначала напиши /leave");
			return;
		}
		$this->state[$p->getName()] = "queue";
		$this->queues[$mode][] = $p->getName();
		$p->sendMessage("§aТы в очереди: §f$mode");
		$this->tryMatch($mode);
	}

	private function freeArena(): ?string {
		foreach (array_keys($this->getConfig()->get("arenas", [])) as $name) {
			$name = (string) $name;
			if (empty($this->busyArenas[$name]) && $this->arenaPos($name, 1) !== null && $this->arenaPos($name, 2) !== null) {
				return $name;
			}
		}
		return null;
	}

	private function tryMatch(string $mode): void {
		$modes = $this->getConfig()->get("modes", []);
		if (!isset($modes[$mode])) {
			return;
		}
		$size = (int) $modes[$mode]["size"];
		$need = $size * 2;

		$q = $this->queues[$mode] ?? [];
		$q = array_values(array_filter($q, fn(string $n) => $this->getServer()->getPlayerExact($n) !== null));
		$this->queues[$mode] = $q;

		if (count($q) < $need) {
			return;
		}
		$arena = $this->freeArena();
		if ($arena === null) {
			return; // все арены заняты, ждём
		}
		$names = array_splice($this->queues[$mode], 0, $need);
		$this->startDuel($mode, $arena, array_slice($names, 0, $size), array_slice($names, $size));
	}

	/**
	 * @param string[] $a
	 * @param string[] $b
	 */
	private function startDuel(string $mode, string $arena, array $a, array $b): void {
		$duel = new Duel($mode, $arena, $a, $b);
		$this->busyArenas[$arena] = true;
		$kit = (string) ($this->getConfig()->get("modes")[$mode]["kit"] ?? "duel");

		$spawn = [
			"a" => $this->arenaPos($arena, 1),
			"b" => $this->arenaPos($arena, 2),
		];

		foreach ($duel->alive as $name) {
			$p = $this->getServer()->getPlayerExact($name);
			if ($p === null) {
				continue;
			}
			$this->state[$name] = "duel";
			$this->inDuel[$name] = $duel;
			$this->giveKit($p, $kit);
			$team = $duel->teamOf($name);
			if ($team !== null && $spawn[$team] !== null) {
				$p->teleport($spawn[$team]);
			}
			$p->setNoClientPredictions(true);
			$p->sendMessage("§aДуэль найдена! Режим: §f$mode");
		}

		// Отсчёт 3-2-1
		foreach ([3, 2, 1] as $i) {
			$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($duel, $i): void {
				if ($duel->ended) {
					return;
				}
				foreach ($duel->alive as $n) {
					$pl = $this->getServer()->getPlayerExact($n);
					$pl?->sendTitle("§e" . $i);
				}
			}), (3 - $i) * 20);
		}
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($duel): void {
			if ($duel->ended) {
				return;
			}
			$duel->started = true;
			foreach ($duel->alive as $n) {
				$pl = $this->getServer()->getPlayerExact($n);
				if ($pl !== null) {
					$pl->setNoClientPredictions(false);
					$pl->sendTitle("§aБОЙ!");
				}
			}
		}), 60);
	}

	private function checkEnd(Duel $duel): void {
		if ($duel->ended) {
			return;
		}
		$aliveA = array_filter($duel->alive, fn(string $n) => $duel->teamOf($n) === "a");
		$aliveB = array_filter($duel->alive, fn(string $n) => $duel->teamOf($n) === "b");
		if (count($aliveA) > 0 && count($aliveB) > 0) {
			return;
		}
		$duel->ended = true;
		$winners = array_values(array_merge($aliveA, $aliveB));

		foreach ($winners as $n) {
			$w = $this->getServer()->getPlayerExact($n);
			$w?->sendTitle("§6ПОБЕДА!");
		}

		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($duel, $winners): void {
			foreach ($winners as $n) {
				$w = $this->getServer()->getPlayerExact($n);
				if ($w !== null) {
					$this->toLobby($w);
				}
			}
			unset($this->busyArenas[$duel->arena]);
			foreach (array_keys($this->getConfig()->get("modes", [])) as $m) {
				$this->tryMatch((string) $m);
			}
		}), 60);
	}

	private function eliminate(Player $victim, ?Player $killer): void {
		$name = $victim->getName();
		$st = $this->stateOf($victim);

		if ($st === "ffa") {
			if ($killer !== null && $killer !== $victim) {
				$killer->setHealth($killer->getMaxHealth());
				$killer->sendMessage("§aТы убил §f" . $name);
				$victim->sendMessage("§cТебя убил §f" . $killer->getName());
			}
			$this->enterFfa($victim);
			return;
		}

		if ($st === "duel") {
			$duel = $this->inDuel[$name] ?? null;
			if ($duel === null) {
				$this->toLobby($victim);
				return;
			}
			$duel->alive = array_values(array_diff($duel->alive, [$name]));
			$victim->sendMessage("§cТы проиграл");
			$this->toLobby($victim);
			$this->checkEnd($duel);
			return;
		}

		$this->toLobby($victim);
	}

	// ================= СОБЫТИЯ =================

	public function onJoin(PlayerJoinEvent $e): void {
		$this->toLobby($e->getPlayer());
	}

	public function onQuit(PlayerQuitEvent $e): void {
		$p = $e->getPlayer();
		$name = $p->getName();
		$this->removeFromQueue($name);
		$duel = $this->inDuel[$name] ?? null;
		if ($duel !== null) {
			$duel->alive = array_values(array_diff($duel->alive, [$name]));
			unset($this->inDuel[$name]);
			$this->checkEnd($duel);
		}
		unset($this->state[$name]);
	}

	/**
	 * Главная часть: свой кнокбэк + задержка удара + смерть без экрана смерти.
	 * @priority HIGH
	 */
	public function onDamage(EntityDamageEvent $e): void {
		$victim = $e->getEntity();
		if (!$victim instanceof Player) {
			return;
		}
		$st = $this->stateOf($victim);

		if ($st !== "ffa" && $st !== "duel") {
			$e->cancel();
			return;
		}

		$vDuel = $this->inDuel[$victim->getName()] ?? null;
		if ($st === "duel" && ($vDuel === null || !$vDuel->started || $vDuel->ended)) {
			$e->cancel();
			return;
		}

		$attacker = null;
		if ($e instanceof EntityDamageByEntityEvent) {
			$d = $e->getDamager();
			if ($d instanceof Player) {
				$attacker = $d;
				if ($this->stateOf($d) !== $st) {
					$e->cancel();
					return;
				}
				if ($st === "duel") {
					$aDuel = $this->inDuel[$d->getName()] ?? null;
					if ($aDuel !== $vDuel || $vDuel->teamOf($d->getName()) === $vDuel->teamOf($victim->getName())) {
						$e->cancel();
						return;
					}
				}
			}
		}

		// Смертельный удар: убираем экран смерти
		if ($victim->getHealth() - $e->getFinalDamage() <= 0) {
			$e->cancel();
			$this->eliminate($victim, $attacker);
			return;
		}

		if ($attacker !== null && $e instanceof EntityDamageByEntityEvent) {
			$cfg = $this->getConfig()->get("knockback", []);
			$h = (float) ($cfg["horizontal"] ?? 0.3);
			$v = (float) ($cfg["vertical"] ?? 1.1);
			$delay = (int) ($cfg["attack-delay"] ?? 4);

			$e->setAttackCooldown($delay);
			$e->setKnockBack(0.0); // отключаем стандартный кб

			$dx = $victim->getLocation()->x - $attacker->getLocation()->x;
			$dz = $victim->getLocation()->z - $attacker->getLocation()->z;
			$len = sqrt($dx * $dx + $dz * $dz);
			if ($len > 0.0001) {
				$dx /= $len;
				$dz /= $len;
			} else {
				$dx = 0.0;
				$dz = 0.0;
			}

			// Ставим свою скорость на следующий тик (после стандартного кб)
			$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($victim, $dx, $dz, $h, $v): void {
				if ($victim->isOnline() && $victim->isAlive()) {
					$victim->setMotion(new Vector3($dx * $h, $v, $dz * $h));
				}
			}), 1);
		}
	}

	public function onExhaust(PlayerExhaustEvent $e): void {
		$e->cancel();
	}

	public function onDrop(PlayerDropItemEvent $e): void {
		if (!$e->getPlayer()->hasPermission("zonepractice.admin")) {
			$e->cancel();
		}
	}

	public function onBreak(BlockBreakEvent $e): void {
		if (!$e->getPlayer()->hasPermission("zonepractice.admin")) {
			$e->cancel();
		}
	}

	public function onPlace(BlockPlaceEvent $e): void {
		if (!$e->getPlayer()->hasPermission("zonepractice.admin")) {
			$e->cancel();
		}
	}

	// ================= КОМАНДЫ =================

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if (!$sender instanceof Player) {
			$sender->sendMessage("Команды только в игре.");
			return true;
		}
		switch ($command->getName()) {
			case "duel":
				$this->joinQueue($sender, strtolower($args[0] ?? ""));
				return true;

			case "ffa":
				if ($this->stateOf($sender) !== "lobby") {
					$sender->sendMessage("§cСначала напиши /leave");
					return true;
				}
				$this->enterFfa($sender);
				return true;

			case "leave":
				if ($this->stateOf($sender) === "duel") {
					$this->eliminate($sender, null);
				} else {
					$this->toLobby($sender);
				}
				return true;

			case "zp":
				return $this->adminCommand($sender, $args);
		}
		return false;
	}

	private function adminCommand(Player $p, array $args): bool {
		$sub = strtolower($args[0] ?? "");
		$l = $p->getLocation();
		$data = ["world" => $p->getWorld()->getFolderName(), "x" => round($l->x, 1), "y" => round($l->y, 1), "z" => round($l->z, 1)];
		$cfg = $this->getConfig();

		switch ($sub) {
			case "setlobby":
				$cfg->set("lobby", $data);
				$cfg->save();
				$p->sendMessage("§aЛобби установлено");
				return true;

			case "setffa":
				$cfg->set("ffa", $data);
				$cfg->save();
				$p->sendMessage("§aСпавн FFA установлен");
				return true;

			case "setarena":
				$name = $args[1] ?? "";
				$idx = (int) ($args[2] ?? 0);
				if ($name === "" || ($idx !== 1 && $idx !== 2)) {
					$p->sendMessage("§c/zp setarena <имя> <1|2>");
					return true;
				}
				$cfg->setNested("arenas.$name.world", $data["world"]);
				$cfg->setNested("arenas.$name.p$idx", ["x" => $data["x"], "y" => $data["y"], "z" => $data["z"]]);
				$cfg->save();
				$p->sendMessage("§aАрена $name, точка $idx сохранена");
				return true;
		}
		$p->sendMessage("§c/zp <setlobby|setffa|setarena>");
		return true;
	}
}
