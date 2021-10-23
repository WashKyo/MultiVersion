<?php

declare(strict_types=1);

namespace AkmalFairuz\MultiVersion\network\translator;

use AkmalFairuz\MultiVersion\network\ProtocolConstants;
use pocketmine\inventory\FurnaceRecipe;
use pocketmine\inventory\ShapedRecipe;
use pocketmine\inventory\ShapelessRecipe;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\utils\BinaryStream;
use function count;
use function str_repeat;

class CraftingDataPacketTranslator{

    private static function writeEntry($entry, NetworkBinaryStream $stream, int $pos) : int{
        if($entry instanceof ShapelessRecipe){
            return self::writeShapelessRecipe($entry, $stream, $pos);
        }elseif($entry instanceof ShapedRecipe){
            return self::writeShapedRecipe($entry, $stream, $pos);
        }elseif($entry instanceof FurnaceRecipe){
            return self::writeFurnaceRecipe($entry, $stream);
        }
        //TODO: add MultiRecipe

        return -1;
    }

    private static function writeShapelessRecipe(ShapelessRecipe $recipe, NetworkBinaryStream $stream, int $pos) : int{
        $stream->putString((\pack("N", $pos))); //some kind of recipe ID, doesn't matter what it is as long as it's unique
        $stream->putUnsignedVarInt($recipe->getIngredientCount());
        foreach($recipe->getIngredientList() as $item){
            $stream->putRecipeIngredient($item);
        }

        $results = $recipe->getResults();
        $stream->putUnsignedVarInt(count($results));
        foreach($results as $item){
            $stream->putItemStackWithoutStackId($item);
        }

        $stream->put(str_repeat("\x00", 16)); //Null UUID
        $stream->putString("crafting_table"); //TODO: blocktype (no prefix) (this might require internal API breaks)
        $stream->putVarInt(50); //TODO: priority
        $stream->writeGenericTypeNetworkId($pos); //TODO: ANOTHER recipe ID, only used on the network

        return CraftingDataPacket::ENTRY_SHAPELESS;
    }

    private static function writeShapedRecipe(ShapedRecipe $recipe, NetworkBinaryStream $stream, int $pos) : int{
        $stream->putString((\pack("N", $pos))); //some kind of recipe ID, doesn't matter what it is as long as it's unique
        $stream->putVarInt($recipe->getWidth());
        $stream->putVarInt($recipe->getHeight());

        for($z = 0; $z < $recipe->getHeight(); ++$z){
            for($x = 0; $x < $recipe->getWidth(); ++$x){
                $stream->putRecipeIngredient($recipe->getIngredient($x, $z));
            }
        }

        $results = $recipe->getResults();
        $stream->putUnsignedVarInt(count($results));
        foreach($results as $item){
            $stream->putItemStackWithoutStackId($item);
        }

        $stream->put(str_repeat("\x00", 16)); //Null UUID
        $stream->putString("crafting_table"); //TODO: blocktype (no prefix) (this might require internal API breaks)
        $stream->putVarInt(50); //TODO: priority
        $stream->writeGenericTypeNetworkId($pos); //TODO: ANOTHER recipe ID, only used on the network

        return CraftingDataPacket::ENTRY_SHAPED;
    }

    private static function writeFurnaceRecipe(FurnaceRecipe $recipe, NetworkBinaryStream $stream) : int{
        $input = $recipe->getInput();
        if($input->hasAnyDamageValue()){
            [$netId, ] = ItemTranslator::getInstance()->toNetworkId($input->getId(), 0);
            $netData = 0x7fff;
        }else{
            [$netId, $netData] = ItemTranslator::getInstance()->toNetworkId($input->getId(), $input->getDamage());
        }
        $stream->putVarInt($netId);
        $stream->putVarInt($netData);
        $stream->putItemStackWithoutStackId($recipe->getResult());
        $stream->putString("furnace"); //TODO: blocktype (no prefix) (this might require internal API breaks)
        return CraftingDataPacket::ENTRY_FURNACE_DATA;
    }

    public static function serialize(CraftingDataPacket $packet, int $protocol) {
        $packet->putUnsignedVarInt(count($packet->entries));

        $writer = new NetworkBinaryStream();
        $counter = 0;
        foreach($packet->entries as $d){
            $entryType = self::writeEntry($d, $writer, ++$counter);
            if($entryType >= 0){
                $packet->putVarInt($entryType);
                ($packet->buffer .= $writer->getBuffer());
            }else{
                $packet->putVarInt(-1);
            }

            $writer->reset();
        }
        $packet->putUnsignedVarInt(count($packet->potionTypeRecipes));
        foreach($packet->potionTypeRecipes as $recipe){
            $packet->putVarInt($recipe->getInputItemId());
            $packet->putVarInt($recipe->getInputItemMeta());
            $packet->putVarInt($recipe->getIngredientItemId());
            $packet->putVarInt($recipe->getIngredientItemMeta());
            $packet->putVarInt($recipe->getOutputItemId());
            $packet->putVarInt($recipe->getOutputItemMeta());
        }
        $packet->putUnsignedVarInt(count($packet->potionContainerRecipes));
        foreach($packet->potionContainerRecipes as $recipe){
            $packet->putVarInt($recipe->getInputItemId());
            $packet->putVarInt($recipe->getIngredientItemId());
            $packet->putVarInt($recipe->getOutputItemId());
        }
        if($protocol >= ProtocolConstants::BEDROCK_1_17_30){
            $packet->putUnsignedVarInt(count($packet->materialReducerRecipes));
            foreach($packet->materialReducerRecipes as $recipe){
                $packet->putVarInt(($recipe->getInputItemId() << 16) | $recipe->getInputItemMeta());
                $packet->putUnsignedVarInt(count($recipe->getOutputs()));
                foreach($recipe->getOutputs() as $output){
                    $packet->putVarInt($output->getItemId());
                    $packet->putVarInt($output->getCount());
                }
            }
        }
        ($packet->buffer .= ($packet->cleanRecipes ? "\x01" : "\x00"));
    }
}