<?php
/*
*
* Copyright (C) 2017 Muqsit Rayyan
*
*  _____ _               _   _____ _                 
* /  __ \ |             | | /  ___| |                
* | /  \/ |__   ___  ___| |_\ `--.| |__   ___  _ __  
* | |   | '_ \ / _ \/ __| __|`--. \ '_ \ / _ \| '_ \ 
* | \__/\ | | |  __/\__ \ |_/\__/ / | | | (_) | |_) |
*  \____/_| |_|\___||___/\__\____/|_| |_|\___/| .__/ 
*                                             | |    
*                                             |_|    
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Lesser General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
*
* @author Muqsit Rayyan
*
*/
namespace ChestShop;

use muqsit\invmenu\{InvMenu, InvMenuHandler};

use pocketmine\block\Block;
use pocketmine\command\{Command, CommandSender};
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat as TF;

class Main extends PluginBase{

	const PREFIX = TF::BOLD.TF::YELLOW.'CS '.TF::RESET;

	const CONFIG = [
		'config.yml' => [
			'default-price' => 15000,
			'banned-items' => [
				'35:1',
				7,
			],
			'enable-sync' => false
		]
	];

	const HELP_CMD = [
		TF::YELLOW.TF::BOLD.'Auction Shop'.TF::RESET,
		TF::YELLOW.'/{cmd} add [price]'.TF::GRAY.' - Add the item in your hand to the Auction House.',
		TF::YELLOW.'/{cmd} remove [page] [slot]'.TF::GRAY.' - Remove an item off the Auction House.',
		TF::YELLOW.'/{cmd} reload'.TF::GRAY.' - Reload the plugin (to fix errors or refresh data).'
	];

	const LEFT_TURNER = 0;
	const RIGHT_TURNER = 1;

	const NBT_TURNER_DIRECTION = 0;
	const NBT_TURNER_CURRENTPAGE = 1;

	const NBT_CHESTSHOP_PRICE = 0;
	const NBT_CHESTSHOP_ID = 1;

	/** @var int */
	public $defaultprice;

	/** @var Item[] */
	protected $shops = [];

	/** @var Main */
	private static $instance;

	/** @var int[] */
	private $notallowed = [];

	/** @var bool */
	private $economyshop = false;

	/** @var InvMenu */
	private $menu;

	public function onEnable() : void{
		self::$instance = $this;
		$this->getLogger()->notice(implode("\n", [
			" ",
			" _____ _               _   _____ _                 ",
			"/  __ \ |             | | /  ___| |                ",
			"| /  \/ |__   ___  ___| |_\ `--.| |__   ___  _ __  ",
			"| |   | '_ \ / _ \/ __| __|`--. \ '_ \ / _ \| '_ \ ",
			"| \__/\ | | |  __/\__ \ |_/\__/ / | | | (_) | |_) |",
			" \____/_| |_|\___||___/\__\____/|_| |_|\___/| .__/ ",
			"                                            | |    ",
			"                                            |_|    ",
			" ",
			"@author Muqsit Rayyan",
			" "
		]));

		if(!is_dir($this->getDataFolder())){
			mkdir($this->getDataFolder());
		}

		foreach(array_keys(self::CONFIG) as $file){
			$this->updateConfig($file);
		}

		$shops = yaml_parse_file($this->getDataFolder().'shops.yml');
		if(!empty($shops)){
			$this->shops = $shops;
		}

		$config = yaml_parse_file($this->getDataFolder().'config.yml');
		$this->defaultprice = $config["default-price"] ?? 15000;
		$this->notallowed = array_flip($config["banned-items"] ?? []);

		if($config['enable-sync'] == true){
			$this->economyshop = $this->getServer()->getPluginManager()->getPlugin('EconomyShop');
		}else{
			$this->economyshop = false;
		}

		$this->initializeMenu();
	}

	/**
	 * @return Main
	 */
	public static function getInstance() : Main{
		return self::$instance;
	}

	public function initializeMenu() : void{
		if(!class_exists(InvMenu::class)){
			$this->getLogger()->warning($this->getName()." uses the virion 'InvMenu' which doesn't exist. Please use the pre-built .phar file from poggit. If you would still like to continue running ChestShop from source, install DEVirion plugin and InvMenu virion and place InvMenu in the /virions folder.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		if(!InvMenuHandler::isRegistered()){
			InvMenuHandler::register($this);
		}

		$this->menu = InvMenu::create(InvMenu::TYPE_CHEST)
			->readOnly()
			->sessionize()
			->setName("SkyRealm's Auction")
			->setListener([new ShopListener($this), "onTransaction"]);
	}

	public function onDisable() : void{
		yaml_emit_file($this->getDataFolder().'shops.yml', $this->shops);
	}

	/**
	 * Updates config with newer data.
	 */
	private function updateConfig(string $config) : void{
		if(isset(self::CONFIG[$config])){
			$path = $this->getDataFolder().$config;
			$data = is_file($path) ? yaml_parse_file($path) : self::CONFIG[$config];
			foreach(self::CONFIG[$config] as $key => $value){
				if(!isset($data[$key])){
					$data[$key] = $value;
				}
			}
			yaml_emit_file($path, $data);
		}
	}

	/**
	 * Sends the chest shop (inventory)
	 * to the player.
	 *
	 * @param Player $player
	 */
	public function sendChestShop(Player $player) : void{
		$this->fillInventoryWithShop($this->menu->getInventory($player));//this is a big NO tbh, uh
		$this->menu->send($player);
	}

	public function reload() : void{
		$this->onDisable();
		$this->shops = [];
		$shops = yaml_parse_file($this->getDataFolder().'shops.yml');
		if(!empty($shops)){
			$this->shops = $shops;
		}
		$config = yaml_parse_file($this->getDataFolder().'config.yml');
		$this->defaultprice = $config["default-price"] ?? 15000;
		$this->notallowed = array_flip($config["banned-items"] ?? []);
	}

	/**
	 * Get item from shop via shop ID.
	 *
	 * @param int $id
	 * @return Item
	 */
	public function getItemFromShop(int $id) : Item{
		if(isset($this->shops[$id])){
			$data = $this->shops[$id];
			return Item::get($data[0], $data[1], $data[2])->setNamedTag(unserialize($data[3]));
		}
		return Item::get(Item::AIR, 0, 0);
	}

	/**
	 * Fills the $inventory with contents
	 * of chest shop.
	 *
	 * @param ChestInventory $inventory
	 * @param int $page
	 */
	public function fillInventoryWithShop(Inventory $inventory, int $page = 0) : void{
		$contents = [];

		if(count($this->shops) !== 0) {
			$chunked = array_chunk($this->shops, 24, true);
			if($page < 0){
				$page = count($chunked) - 1;
			}
			$page = isset($chunked[$page]) ? $page : 0;
			foreach($chunked[$page] as $data){
				$item = Item::get($data[0], $data[1], $data[2]);
				if($data[3] === null){
					break;
				}

				$item->setNamedTag($nbt = unserialize($data[3]));
				$item->setCustomName(TF::RESET.$item->getName()."\n \n".TF::YELLOW.'Tap to purchase for '.TF::BOLD.'$'.$nbt->getIntArray('Auction')[0].TF::RESET);
				$contents[] = $item;
			}
		}

		// Page turners.
		$turnleft = Item::get(Item::PAPER);
		$turnleft->setCustomName(TF::RESET.TF::GOLD.TF::BOLD.'<< Turn Left'.TF::RESET."\n".TF::GRAY.'Turn towards the left.');
		$turnleft->setNamedTagEntry(new IntArrayTag('turner', [self::LEFT_TURNER, $page]));
		$contents[25] = $turnleft;

		$turnright = Item::get(Item::PAPER);
		$turnright->setCustomName(TF::RESET.TF::GOLD.TF::BOLD.'Turn Right >>'.TF::RESET."\n".TF::GRAY.'Turn towards the right.');
		$turnright->setNamedTagEntry(new IntArrayTag('turner', [self::RIGHT_TURNER, $page]));
		$contents[26] = $turnright;

		$inventory->setContents($contents);
	}

	/**
	 * Adds an item to the chest shop.
	 *
	 * @param Item $item
	 * @param int $price
	 */
	public function addToChestShop(Item $item, int $price) : void{
		while(isset($this->shops[$key = rand()]));
		$item->setNamedTagEntry(new IntArrayTag('Auction', [$price, $key]));
		$this->shops[$key] = [$item->getId(), $item->getDamage(), $item->getCount(), serialize($item->getNamedTag())];
	}

	/**
	 * Removes an item off the chest shop.
	 *
	 * @param int $page
	 * @param int $slot
	 */
	public function removeItemOffShop(int $page, int $slot) : void{
		if(count($this->shops) === 0){
			return;
		}
		$keys = array_keys($this->shops);//$this->shops is an associative array.
		$key = 24 * $page + $slot;//array_chunks divides $shops into 24 parts in the GUI.
		unset($this->shops[$keys[--$key]]);//$slot - 1. Slots are counted from 0. If $slot is 1, the issuer probably (actually) is referring to slot zero.
	}

	/**
	 * Checks whether or not it's allowed
	 * to put an item in the chest shop.
	 * This can be set up in the config
	 * (banned-items key in the config).
	 *
	 * @param int $itemId
	 * @param int $itemDamage
	 * @return bool
	 */
	private function isNotAllowed(int $itemId, int $itemDamage = 0) : bool{
		if($itemDamage === 0){
			return isset($this->notallowed[$itemId]) || isset($this->notallowed[$itemId.':'.$itemDamage]);
		}
		return isset($this->notallowed[$itemId.':'.$itemDamage]);
	}

	/**
	 * Removes item off chest shop
	 * by key.
	 *
	 * @param int $keys
	 */
	public function removeItemsByKey(int ...$keys) : void{
		foreach($keys as $key){
			unset($this->shops[$key]);
		}
	}

	public function onCommand(CommandSender $sender, Command $cmd, $label, array $args) : bool{
		if(isset($args[0])){
			switch(strtolower($args[0])){
				case "help":
					$sender->sendMessage(str_replace('{cmd}', $cmd, implode("\n", self::HELP_CMD)));
					break;
				case "about":
					$sender->sendMessage(TF::YELLOW.TF::BOLD.'Auction House'.TF::RESET."\n".TF::GRAY.'Created by SkyRealmNetwork('.TF::AQUA.'@skyrealmnetwork'.TF::GRAY.').');
					break;
				case "add":
					if($sender->hasPermission('auction.command.add')){
						$item = $sender->getInventory()->getItemInHand();
						if($item->isNull()){
							$sender->sendMessage(self::PREFIX.TF::RED.'Please hold an item in your hand.');
						}elseif($this->isNotAllowed($item->getId(), $item->getDamage())){
							$sender->sendMessage(self::PREFIX.TF::RED.'You cannot sell '.((Item::get($item->getId(), $item->getDamage()))->getName()).' on /ah.');
						}else{
							if(isset($args[1]) && is_numeric($args[1]) && $args[1] >= 0) {
								$sender->sendMessage(self::PREFIX.TF::YELLOW.'Added '.(explode("\n", $item->getName())[0]).' to '.$cmd->getName().' for $'.$args[1].'.');
								$this->addToChestShop($item, $args[1]);
							}else{
								$sender->sendMessage(TF::RED.'Please enter a valid number.');
							}
						}
					}
					break;
				case "removebyid":
					if($sender->hasPermission('auction.command.remove')){
						if(isset($args[1]) && is_numeric($args[1]) && $args[1] >= 1){
							$damage = $args[2] ?? 0;
							if(count($this->shops) <= 27){
								$i = 0;
								foreach($this->shops as $k => $item){
									if($item[0] == $args[1] && $item[1] == $damage){
										unset($this->shops[$k]);
										++$i;
									}
								}
								$sender->sendMessage(self::PREFIX.TF::YELLOW.$i.' items were removed off auction house (ID: '.$args[1].', DAMAGE: '.$damage.').');
							}else{
								$this->getServer()->getScheduler()->scheduleAsyncTask(new RemoveByIdTask([$sender->getName(), $args[1], $damage, &$this->shops]));
							}
						}else{
							$sender->sendMessage(self::PREFIX.TF::YELLOW.'Usage: /'.$cmd->getName().' removebyid [item-id] [item-damage]');
						}
					}
					break;
				case "remove":
					if($sender->hasPermission('auction.command.remove')){
						if(isset($args[1], $args[2]) && is_numeric($args[1]) && is_numeric($args[2]) && ($args[1] >= 0) && ($args[2] >= 1)){
							$sender->sendMessage(self::PREFIX.TF::YELLOW.'Removed item on page #'.$args[1].', slot #'.$args[2].'.');
							$this->removeItemOffShop($args[1], $args[2]);
						}else{
							$sender->sendMessage(TF::RED.'Page number and item slot must be integers (page > -1, slot > 0).');
						}
					}
					break;
				case "reload":
					if($sender->hasPermission('auction.command.reload')){
						$sender->sendMessage(self::PREFIX.TF::AQUA.'ChestShop is reloading...');
						$this->reload();
						$sender->sendMessage(self::PREFIX.TF::AQUA.'ChestShop has reloaded successfully.');
					}
					break;
				case "synceconomy":
					if($sender->hasPermission('auction.command.opcmd')){
						switch($this->economyshop){
							case null:
								$sender->sendMessage(TF::RED."Couldn't find EconomyShop plugin. Make sure the plugin is enabled and running.");
								return false;
							case false:
								$sender->sendMessage(TF::RED.'You must set the "enable-sync" option to true in the config to use this command.');
								return false;
						}
						$provider = new \onebone\economyshop\provider\YAMLDataProvider($this->economyshop->getDataFolder().'Shops.yml', false);//read-only

						$this->getLogger()->info($sender->getName().' is synchronizing data from EconomyShop.');
						$time = microtime(true);

						foreach($provider->getAll() as $shop){
							if (is_string($shop["item"] ?? $shop[4])){
								$itemId = ItemFactory::fromString((string) ($shop["item"] ?? $shop[4]), false)->getId();
							}else{
								$itemId = ItemFactory::get((int) ($shop["item"] ?? $shop[4]), false)->getId();
							}
							$item = ItemFactory::get($itemId, (int) ($shop["meta"] ?? $shop[5]), (int) ($shop["amount"] ?? $shop[7]));

							$this->addToChestShop($item, $shop["price"] ?? $shop[8]);
						}

						$time = microtime(true) - $time;
						$sender->sendMessage(TF::YELLOW.'Data synchronized. Took '.TF::GREEN.$time.TF::YELLOW.'s.');
						$this->getLogger()->info($sender->getName().' has synchronized data from EconomyShop (Took '.$time.' seconds).');
					}
					break;
				default:
					$sender->sendMessage(TF::RED.'Type /ah help to get a list of auction house help commands.');
					break;
			}
		}else{
			if($sender instanceof Player){
				$this->sendChestShop($sender);
			}else{
				$sender->sendMessage(str_replace('{cmd}', $cmd, implode("\n", self::HELP_CMD)));
			}
		}
		return true;
	}
}
