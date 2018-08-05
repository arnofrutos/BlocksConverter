<?php

declare(strict_types=1);

namespace matcracker\BlocksConverter;

use pocketmine\block\Block;
use pocketmine\block\SignPost;
use pocketmine\block\WallSign;
use pocketmine\level\format\EmptySubChunk;
use pocketmine\level\Level;
use pocketmine\tile\Sign;
use pocketmine\tile\Skull;
use pocketmine\utils\TextFormat;

class LevelManager
{
    const IGNORE_DATA_VALUE = 99;

    /**@var Loader $loader */
    private $loader;
    /**@var Level $level */
    private $level;
    /**@var bool $converting */
    private $converting = false;

    private $chunkcount = 0;

    private $colors = array(
      "black" => TextFormat::BLACK,
        "dark_blue" => TextFormat::DARK_BLUE,
        "dark_green" => TextFormat::DARK_GREEN,
        "dark_aqua" => TextFormat::DARK_AQUA,
        "dark_red" => TextFormat::DARK_RED,
        "dark_purple" => TextFormat::DARK_PURPLE,
        "old" => TextFormat::GOLD,
        "gray" => TextFormat::GRAY,
        "dark_gray" => TextFormat::DARK_GRAY,
        "blue" => TextFormat::BLUE,
        "green" => TextFormat::GREEN,
        "aqua" => TextFormat::AQUA,
        "red" => TextFormat::RED,
        "light_purple" => TextFormat::LIGHT_PURPLE,
        "yellow" => TextFormat::YELLOW,
        "white" => TextFormat::WHITE
    );

    public function __construct(Loader $loader, Level $level)
    {
        $this->loader = $loader;
        $this->level = $level;
    }

    public function getLevel() : Level
    {
        return $this->level;
    }

    public function backup() : void
    {
        $this->loader->getLogger()->debug(Utils::translateColors("§6Creating a backup of " . $this->level->getName()));
        $srcPath = $this->loader->getServer()->getDataPath() . "/worlds/" . $this->level->getFolderName();
        $destPath = $this->loader->getDataFolder() . "/backups/" . $this->level->getFolderName();
        Utils::copyDirectory($srcPath, $destPath);
        $this->loader->getLogger()->debug(Utils::translateColors("§aBackup successfully created!"));
    }

    public function restore() : void
    {
        $srcPath = $this->loader->getDataFolder() . "/backups/" . $this->level->getFolderName();
        if (!$this->hasBackup()) {
            throw new \InvalidStateException("This world never gets a backup.");
        }

        $destPath = $this->loader->getServer()->getDataPath() . "/worlds/" . $this->level->getFolderName();

        Utils::copyDirectory($srcPath, $destPath);
    }

    public function hasBackup() : bool
    {
        return file_exists($this->loader->getDataFolder() . "/backups/" . $this->level->getFolderName());
    }

    private function loadChunks() : void
    {
        $map = $this->loader->getServer()->getDataPath() . "/worlds/" . $this->level->getFolderName() . "/region";

        foreach (scandir($map) as $file){
            if (strlen($file) > 3) {
                $split = explode(".", $file);
                $x = (int)$split[1];
                $z = (int)$split[2];
                for ($i = $x * 32; $i < ($x + 1) * 32; $i++){
                    for ($j = $z * 32; $j < ($z + 1) * 32; $j++){
                        if ($this->level->loadChunk($i, $j, false)){
                            $this->chunkcount++;
                        }
                    }
                }
            }
        }

        $this->loader->getLogger()->info("Total counted chunks: " . + $this->chunkcount);
        $this->loader->getLogger()->info("Total loaded chunks: " . + count($this->level->getChunks()));
    }

    public function unloadLevel() : bool
    {
        return $this->loader->getServer()->unloadLevel($this->level);
    }

    public function isConverting() : bool
    {
        return $this->converting;
    }

    private function startAnalysis() : array
    {
        $errors = [];

        if (!empty($this->loader->getBlocksData())) {
            /**@var string $blockVal */
            foreach (array_keys($this->loader->getBlocksData()) as $blockVal) {
                $blockVal = (string)$blockVal;
                $explode = explode("-", $blockVal);
                if (count($explode) !== 2) {
                    $errors[] = "$blockVal is not a correct configuration value, it should be ID-Data (e.g. 1-0)";
                }
            }
        } else {
            $errors[] = "The configuration key \"blocks\" of blocks.yml file is empty, you could not run the conversion!";
        }

        return $errors;
    }

    public function startConversion() : void
    {
        //Conversion report variables
        $status = true;
        $chunksAnalyzed = $subChunksAnalyzed = $convertedBlocks = $convertedSigns = 0;

        $time_start = microtime(true);

        /**@var string[] $errors */
        $errors = $this->startAnalysis();

        if (!empty($errors)) {
            $this->loader->getLogger()->error("Found " . count($errors) . " error(s) before starting the conversion. List:");
            foreach ($errors as $error) {
                $this->loader->getLogger()->error("- " . $error);
            }
            $status = false;
        } else {
            if (!$this->hasBackup()) {
                $this->loader->getLogger()->warning("The level " . $this->level->getName() . " will be converted without a backup.");
            }

            $this->loader->getLogger()->debug(Utils::translateColors("§6Starting level " . $this->level->getName() . "'s conversion..."));
            foreach ($this->loader->getServer()->getOnlinePlayers() as $player) {
                $player->kick("The server is running a world conversion, try to join later.", false);
            }

            $this->converting = true;
            try {
                $this->loadChunks();

                foreach ($this->level->getChunks() as $chunk) {
                    $changed = false;
                    foreach($chunk->getTiles() as $tile){
                        if ($tile instanceof Sign){
                            $convertedSigns++;
                            for ($i = 0; $i < 4; $i++){
                                $s = $tile->getLine($i);
                                if (strpos($s, "[") !== false){
                                    $data = json_decode($s, true)["extra"][0];
                                    $str = "";
                                    if (is_array($data)){
                                        if (array_key_exists("bold", $data)){
                                            $str = $str . TextFormat::BOLD;
                                        }
                                        if (array_key_exists("color", $data)){
                                            $str = $str . $this->colors[$data["color"]];
                                        }
                                        $str = $str . json_decode('"' . $data["text"] . '"');
                                    } else {
                                        $str = json_decode('"' . $data . '"');
                                    }
                                    $this->loader->getLogger()->info("New line: " . $str);
                                    if ($str != null){
                                        $tile->setLine($i, $str);
                                        $changed = true;
                                    }
                                    /*PRECOLOR $split = explode("\"", $s);
                                    $str = json_decode('"' . $split[3] . '"');
                                    $this->loader->getLogger()->info("New line: " . $str);
                                    if ($str != null){
                                        $tile->setLine($i, $str);
                                        $changed = true;
                                    }*/
                                } else {
                                    $tile->setLine($i, "");
                                    $changed = true;
                                }
                            }
                        } else if ($tile instanceof Skull){
                            $this->getLevel()->setBlockIdAt($tile->getX(), $tile->getY(), $tile->getZ(), 0);
                        }
                    }
                    for ($y = 0; $y < $chunk->getMaxY(); $y++) {
                        $subChunk = $chunk->getSubChunk($y >> 4);
                        if (!($subChunk instanceof EmptySubChunk)) {
                            for ($x = 0; $x < 16; $x++) {
                                for ($z = 0; $z < 16; $z++) {
                                    $blockId = $subChunk->getBlockId($x, $y & 0x0f, $z);
                                    if ($blockId !== Block::AIR) {
                                        $blockData = $subChunk->getBlockData($x, $y & 0x0f, $z);
                                        $val = $blockId . "-" . $blockData;
                                        if (array_key_exists($val, $this->loader->getBlocksData())){
                                            $d = $this->loader->getBlocksData()[$val];
                                            $newId = (int)$d["converted-id"];
                                            $newData = (int)$d["converted-data"];
                                            $subChunk->setBlock($x, $y & 0x0f, $z, $newId, $newData);
                                            $changed = true;
                                            $convertedBlocks++;

                                        }
                                    }
                                }
                            }
                            $subChunksAnalyzed++;
                        }
                    }
                    $chunk->setChanged($changed);
                    $chunksAnalyzed++;
                    if ($chunksAnalyzed % 100 == 0){
                        $this->loader->getLogger()->info($chunksAnalyzed . "/" . $this->chunkcount . " have been processed (" . $convertedBlocks . " blocks)(" . $convertedSigns . " signs)");
                    }
                }

                $this->level->save(true);
            } catch (\ErrorException $e) {
                $this->loader->getLogger()->critical($e);
                $status = false;
            }

            $this->converting = false;
            $this->loader->getLogger()->debug("Conversion finished! Printing full report...");

            $report = PHP_EOL . "§d--- Conversion Report ---" . PHP_EOL;
            $report .= "§bStatus: " . ($status ? "§2Completed" : "§cAborted") . PHP_EOL;
            $report .= "§bLevel name: §a" . $this->level->getName() . PHP_EOL;
            $report .= "§bExecution time: §a" . floor(microtime(true) - $time_start) . " second(s)" . PHP_EOL;
            $report .= "§bAnalyzed chunks: §a" . $chunksAnalyzed . PHP_EOL;
            $report .= "§bAnalyzed subchunks: §a" . $subChunksAnalyzed . PHP_EOL;
            $report .= "§bBlocks converted: §a" . $convertedBlocks . PHP_EOL;
            $report .= "§bSigns converted: §a" . $convertedSigns . PHP_EOL;
            $report .= "§d----------";

            $this->loader->getLogger()->info(Utils::translateColors($report));
        }
    }
}