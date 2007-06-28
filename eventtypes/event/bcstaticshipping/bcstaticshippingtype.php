;5A<?php
//
// Definition of BCStaticShippingType class
//
// Created on: <03-24-2007 14:42:02 gb>
//
// COPYRIGHT NOTICE: Copyright (C) 1999-2007 Brookins Consulting
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301,  USA.
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/*! \file bcstaticshippingtype.php
*/

/*!
  \class BCStaticShippingType bcstaticshippingtype.php
  \brief The class BCStaticShippingType handles adding shipping cost to an order
*/

include_once( 'kernel/classes/ezorder.php' );
include_once( 'lib/ezxml/classes/ezxml.php' );

define( 'EZ_WORKFLOW_TYPE_BCSTATICSHIPPING_ID', 'bcstaticshipping' );

class BCStaticShippingType extends eZWorkflowEventType
{
    /*!
     Constructor
    */
    function BCStaticShippingType()
    {
        $this->eZWorkflowEventType( EZ_WORKFLOW_TYPE_BCSTATICSHIPPING_ID, ezi18n( 'kernel/workflow/event', "Static Shipping" ) );
        $this->setTriggerTypes( array( 'shop' => array( 'confirmorder' => array ( 'before' ) ) ) );
    }

    function execute( &$process, &$event )
    {
        // Fetch Workflow Settings
        $ini =& eZINI::instance( 'workflow.ini' );

        // Setting to control free shipping
        $settingFreeShipping = ( $ini->variable( "SimpleShippingWorkflow", "FreeShipping" ) == 'Enabled' );
        $settingFreeShippingPrice = $ini->variable( "SimpleShippingWorkflow", "FreeShippingPrice" );

        // $settingFreeShippingWeightDiscount = $ini->variable( "SimpleShippingWorkflow", "FreeShippingWeightDiscount" );
        $settingFreeShippingDiscount = $ini->variable( "SimpleShippingWorkflow", "FreeShippingDiscount" );

        // Setting to control calculations (Product Option Attribute Processing)
        $settingUseeZoption2ProductVariations = ( $ini->variable( "SimpleShippingWorkflow", "eZoption2ProductVariations" ) == 'Enabled' );

        // Setting for shipping description
        $description = $ini->variable( "SimpleShippingWorkflow", "ShippingDescription" );

        // Setting for shipping vendor name
        $shipping_vendor_name = $ini->variable( "SimpleShippingWorkflow", "ShippingVendorName" );

        // Setting for the default standard shipping cost
        $shipping_default_cost = $ini->variable("SimpleShippingWorkflow", "DefaultStandardShipping" );

        // Setting for shipping calculation process debug
        $debug = $ini->variable( "SimpleShippingWorkflow", "Debug" );

        // Default price values
        $numeric_value_0001 = 0.001;
        $numeric_value_00 = 0.00;
        $numeric_value_01 = 1.00;
        $numeric_value_05 = 5.00;
        $numeric_value_08 = 8.00;
        $numeric_value_09 = 9.00;
        $numeric_value_10 = 10.00;
        $numeric_value_13 = 13.00;
        $numeric_value_15 = 15.00;
        $numeric_value_20 = 20.00;
        $numeric_value_28 = 28.00;
        $numeric_value_30 = 30.00;
        $numeric_value_32 = 32.00;
        $numeric_value_38 = 38.00;
        $numeric_value_40 = 40.00;
        $numeric_value_42 = 42.00;
        $numeric_value_49 = 49.00;
        $numeric_value_50 = 50.00;
        $numeric_value_70 = 70.00;

        // Default cost
        $cost = $numeric_value_00;

        // Set default total weight
        $totalweight = $numeric_value_00;

        // Unknown, Default add shipping to true, we always add shipping
        $addShipping = true;

        // Unknown, Default askvendor to false
        $askvendor = false;

        // Default shipping type name
        $shipping_type_name = "Standard Shipping";

        // Process parameters
        $parameters = $process->attribute( 'parameter_list' );
        $orderID = $parameters['order_id'];

        // Fetch order
        $order = eZOrder::fetch( $orderID );

        // If order class was fetched
        if ( get_class( $order ) == 'ezorder' )
        {
            // Fetch order ezxml document
            $xml = new eZXML();
            $xmlDoc =& $order->attribute( 'data_text_1' );

            // If document is not empty
            if( $xmlDoc != null )
            {
                // get the dom tree of elements
                $dom =& $xml->domTree( $xmlDoc );

                // Default defines
                $state = '';
                $shippingtype = '';
                $shipping_country = '';
                $shipping_s_country = '';
                $shipping = '';

                // Fetch order state
                if ($statedom = $dom->elementsByName( "state" ))
                    $state = $statedom[0]->textContent();

                // Fetch order shipping address checkbox
                if ($shippingdom = $dom->elementsByName( "shipping" ))
                    $shipping = $shippingdom[0]->textContent();

                // Fetch order shipping type
                if ($shippingtypedom = $dom->elementsByName( "shippingtype" ))
                    $shippingtype = $shippingtypedom[0]->textContent();

                // Fetch order country
                if ($shippingcountrydom = $dom->elementsByName( "country" ))
                    $shipping_country = $shippingcountrydom[0]->textContent();

                // Fetch order shipping address country
                if ($shippingcountrydom = $dom->elementsByName( "s_country" ))
                    $shipping_s_country = $shippingcountrydom[0]->textContent();

                // If order has a shipping country use it instead.
                if ( $shipping_s_country != '' )
                    $shipping_country = $shipping_s_country;
            }

            // Fetch order product total price inc tax
            $subtotalprice =& $order->attribute( 'product_total_inc_vat' );
        }

        // Check for defered international shipping address cost calculation by vendor.
        if ( $shipping and $shipping_country !== 'USA' and $shipping_country !== 'CAN' )
        {
            $internationalOrderShippingAddress = true;
            $shippingtype = "2";
            if ( $debug == 'Enabled' )
           echo( 'Shipping International: '. $shipping_country . '--'. $shippingtype .'<hr />' );
        }
        else {
            $internationalOrderShippingAddress = false;
        }

        // Debug
        if ( $debug == 'Enabled' ){
          echo( 'Shipping State: '. $state .'<hr />' );
          echo( 'Shipping Country: '. $shipping_country .'<hr />' );
          echo( 'Shipping Type: '. $shippingtype .'<hr />' );
        }

        // Fetch order products
        $productcollection = $order->productCollection();

        // Fetch order items
        $items = $productcollection->itemList();
        $orderItems = $order->attribute( 'order_items' );

        $freeshippingproduct=false;
        foreach ( $items as $item )
        {
            // fetch order item option
            $option=eZProductCollectionItemOption::fetchList($item->attribute("id"));
            $option=$option[0];
            
            // if there are more than 2 times the product/contentobject id match ordered grant free shipping.
            if ( $item->attribute( 'contentobject_id' ) === "2588")
            {
                if ( $item->ItemCount >= 2 )
                    $freeshippingproduct=true;
            }

            // if product/contentobject id match ordered grant free shipping.
            if ( $item->attribute( 'contentobject_id' ) === "3136")
            {
                    $freeshippingproduct=true;
            }
            
            // Fetch object
            $co = eZContentObject::fetch( $item->attribute( 'contentobject_id' ) );

            // Fetch object datamap
            $dm = $co->dataMap();

	    // If product class using eZOption2 (Re: Product Variations)
            if ( $settingUseeZoption2ProductVariations == 'Enabled' )
            {
                if (!empty($option) )
                {
                    $optionID=$option->OptionItemID;

                    /*
                     Variation
                    */

                    if ( $dm['variation'] )
                    {
                        $content = $dm['variation']->content();
                        $contentopt = $content->Options;
                        $contentoptselect = $contentopt[$optionID];

                        // $itemcost=$contentoptselect["price"] * $item->ItemCount;
                        // $totalcost=$totalcost+$itemcost;

                        $weight = $contentoptselect["weight"] * $item->ItemCount;
                        $totalweight = $totalweight + $weight;

                        if ( !is_object( $content ) )
                            continue;
                        $list = $content->attribute( 'enumobject_list' );

                        if( $list )
                        {
                            foreach ( $list as $element )
                            {
                                $cost = $cost + $item->ItemCount * $element->EnumValue;
                            }
                        }

                        if ( $debug == 'Enabled' ){
                            include_once( 'extension/ezdbug/autoloads/ezdbug.php' );
                            $d = new eZDBugOperators();
                            $d->ezdbugDump( 'Item Accumulative Weight: '. $totalweight, 99, true );
                            echo('<hr />');
                            // die();
                        }
                    }
                }
                else
                {
                    // Conditional, if weight is defined in datamap array
                    if ( $dm['weight'] )
                    {
                        // Fetch weight
                        $count = $item->ItemCount;
                        $weight = $dm['weight']->content();
                        $subtotalweight = $weight * $count;
                        $totalweight = $totalweight + $subtotalweight;
                    }
                }
            }
            else
            {
                // Conditional, if weight is defined in datamap array
                if ( $dm['weight'] )
                {
                    // Fetch weight
                    $count = $item->ItemCount;
                    $weight = $dm['weight']->content();
                    $totalweight = $weight * $count;
                }
            }
        } // End: Order product total weight calculation

        if ( $debug == 'Enabled' )
        {
            include_once( 'extension/ezdbug/autoloads/ezdbug.php' );
            $d = new eZDBugOperators();
            $d->ezdbugDump( 'Order Total Weight: '. $totalweight .'<hr />', 99, true );
        }

        // Fetch Order Items
        $orderItems = $order->attribute( 'order_items' );

        // Ecept when the description is true? Possibly deprecated
        // Unknown, Toggle add shipping to false based on description.
        if ( $settingUseeZoption2ProductVariations == 'Enabled' )
        {
            /*
            foreach ( array_keys( $orderItems ) as $key )
            {
                $orderItem =& $orderItems[$key];
                $shipdisc=strstr($orderItem->attribute( 'description' ) , $description);
                if ( $shipdisc !== false )
                {
                    $addShipping = false;
                    break;
                }
            }
            */
        }

        // Debug
        if ( $debug == 'Enabled' )
        {
            echo( 'Shipping Name: '. $shipping_type_name .'<hr />' );
            echo( 'Shipping Type: '. $shippingtype .'<hr />' );
        }

        // die($shippingtype);

        // Shipping Type: 'Next Day Service'
        if ( $shippingtype == 1 )
        {
            if ( $internationalOrderShippingAddress == true )
            {
                $shipping_type_name = "Next Day Shipping";

                // Default cost
                // Assign Shipping Cost of $00.00
                $cost = $numeric_value_00;
                $askvendor = true;
            }
            else{
                // Default shipping type name
                $shipping_type_name = "Next Day Shipping";

                // Default cost
                // Assign Shipping Cost of $13.00
                $cost = $numeric_value_13;

                // Shipping cost calculation rules
                if ( $totalweight >= $numeric_value_0001 and $totalweight <= $numeric_value_05 )
                {
                    // Assign Shipping Cost of $38.00
                    $cost = $numeric_value_38;
                }
                elseif ( $totalweight > $numeric_value_05 and $totalweight <= $numeric_value_10 )
                {
                    // Assign Shipping Cost of $49.00
                    $cost = $numeric_value_49;
                }
                elseif ( $totalweight > $numeric_value_10 )
                {
                    // Delayed shipping
                    // Assign Shipping Cost of $00.00
                    $cost = $numeric_value_00;
                    $askvendor = true;
                }
            }
        }
        elseif ( $shippingtype == 2 )
        {

            // Shipping Type: '2nd Day / STD INTL'
            // Default shipping type name
            $shipping_type_name = "2nd Day / STD INTL";

            /*
            if ( $internationalOrderShippingAddress == true )
            {
                // Default cost
                // Assign Shipping Cost of $00.00
                $cost = $numeric_value_00;
                $askvendor = true;
            }
            else{
            */

            // Default cost
            // Assign Shipping Cost of $09.00
            $cost = $numeric_value_09;

            // Shipping cost calculation rules
            if( $totalweight >= $numeric_value_0001 and $totalweight <= $numeric_value_05 )
            {
                // Assign Shipping Cost of $28.00
                $cost = $numeric_value_28;
            }
            elseif( $totalweight > $numeric_value_05 and $totalweight <= $numeric_value_10 )
            {
                // Assign Shipping Cost of $40.00
                $cost = $numeric_value_40;
            }
            elseif( $totalweight > $numeric_value_10 )
            {
                // Delayed shipping
                // Assign Shipping Cost of $00.00
                $cost = $numeric_value_00;
                $askvendor = true;
            }
            if ( $debug == 'Enabled' )
            {
                echo( '2nd Day Shipping Cost: '. $cost .'<hr />' );
                echo( '2nd Day Shipping Weight: '. $totalweight .'<hr />' );
                // echo( '2nd Day Shipping Cost: '. $cost .' - '. $totalweight .'<hr />' );
            }
            // }
        }
        else
        {
            // Shipping Type: Standard shipping (Default selection)
            // Default shipping type name
            $shipping_type_name = "Standard Shipping";

            if ( $internationalOrderShippingAddress == true )
            {
                // Default cost
                // Assign Shipping Cost of $00.00
                $cost = $numeric_value_00;
                $askvendor = true;
            }
            else{
                if ( $debug == 'Enabled' )
                echo( 'Shipping Name / Type: '. $shipping_type_name .' - '. $shippingtype .'<hr />' );

                // Default cost
                // Assign Shipping Cost of $09.00
                $cost = $shipping_default_cost;

                /*
                 if ( ( $totalweight >= $numeric_value_00 )?true:false){
                     $totalweight_le_0 = $totalweight <= $numeric_value_00;
                     // echo "$totalweight in weight lte 0.00";
                 }
                 elseif ( ( $totalweight > $numeric_value_70 )?true:false){
                     $totalweight_gt_70 = $totalweight > $numeric_value_70;
                     // echo "$totalweight in weight gt 70";
                 }
                 elseif ( ( $totalweight > $numeric_value_50 )?true:false){
                     $totalweight_gt_50 = $totalweight > $numeric_value_50;
                     // echo "$totalweight in weight gt 50";
                 }
                 elseif ( ( $totalweight > $numeric_value_30 )?true:false){
                     $totalweight_gt_30 = $totalweight > $numeric_value_30;
                     // echo "$totalweight in weight gt 30";
                 }
                 elseif ( ( $totalweight > $numeric_value_20 )?true:false){
                     $totalweight_gt_20 = $totalweight > $numeric_value_20;
                     // echo "$totalweight in weight gt 20";
                 }
                 elseif ( ( $totalweight > $numeric_value_10 )?true:false){
                     $totalweight_gt_10 = $totalweight > $numeric_value_10;
                     // echo "$totalweight in weight gt 10";
                 }
                 else {
                     // echo "no weight, askvendor!!!";
                 }
                 */

                // Absolute Default, Standard shipping cost calculation rules (No Free Shipping, No Shipping Discount)
                if ( $totalweight >= $numeric_value_0001 and $totalweight <= $numeric_value_05 )
                {
                    // Assign Shipping Cost of $08.00
                    $cost = $numeric_value_08;
                    $askvendor = false;
                }
                elseif ( $totalweight > $numeric_value_05 and $totalweight <= $numeric_value_10 )
                {
                    // Assign Shipping Cost of $10.00
                    $cost = $numeric_value_10;
                    $askvendor = false;
                }
                elseif ( $totalweight > $numeric_value_10 and $totalweight <= $numeric_value_20 )
                {
                    // Assign Shipping Cost of $15.00
                    $cost = $numeric_value_15;
                    $askvendor = false;
                }
                elseif ( $totalweight > $numeric_value_20 and $totalweight <= $numeric_value_30 )
                {
                    // Assign Shipping Cost of $20.00
                    $cost = $numeric_value_20;
                    $askvendor = false;
                }
                elseif ( $totalweight > $numeric_value_30 and $totalweight <= $numeric_value_50 )
                {
                    // Assign Shipping Cost of $32.00
                    $cost = $numeric_value_32;
                    $askvendor = false;
                }
                elseif ( $totalweight > $numeric_value_50 and $totalweight <= $numeric_value_70 )
                {
                    // Assign Shipping Cost of $42.00
                    $cost = $numeric_value_42;
                    $askvendor = false;
                }
                elseif ( $totalweight > $numeric_value_70 )
                {
                    // die('fake');
                    // Assign Shipping Cost of $00.00
                    $cost = $numeric_value_00;
                    $askvendor = true;
                }

                if ( $debug == 'Enabled' )
                    echo( "$totalweight".' - Cost Total: '. $cost .'<hr />' );

                // $subtotalprice >= $settingFreeShippingPrice
                // die( '<hr />'. $subtotalprice. '<hr />'. $settingFreeShippingPrice );

                if ( $debug == 'Enabled' )
                {
                    echo( 'Free Shipping Check: '. "$settingFreeShipping and $subtotalprice >= $settingFreeShippingPrice and $settingFreeShippingDiscount and $cost" .'<hr />' );
                    // echo( 'Free Shipping Check: '. "$settingFreeShipping and $subtotalprice >= $settingFreeShippingPrice and $totalweight >= $settingFreeShippingWeightDiscount " .'<hr />' );

                }
                // Calculate free shipping price discount
                if ( $settingFreeShipping and $subtotalprice >= $settingFreeShippingPrice )
                {
                    $containerWeightDiscount = true;

                    // $cost = $cost-$settingFreeShippingDiscount;
                    // $askvendor = false;

                    // $cost = $numeric_value_00;
                    // $originaltotalweight = $totalweight;
                    // $totalweight = $totalweight - $settingFreeShippingWeightDiscount;
                }

                // else {
                // }
                // die( $cost );
            }
        } //??

        if ( $debug == 'Enabled' )
            echo( 'Cost Total: '. $cost .'<hr />' );
        // echo( 'Discount Shipping Check: '. " $addShipping | $shippingtype == 0 and $subtotalprice >= $settingFreeShippingPrice and $totalweight >= $settingFreeShippingWeightDiscount and $subtotalprice >= $settingFreeShippingPrice and $settingFreeShippingDiscount and $cost" .'<hr />' );

        // Are we adding shipping, default is usualy yes here
        if ( $addShipping == true )
        {
            $discount_product_shipping = false;
            $containerWeightDiscount = false;
            

            // Default Description
            $description_default = "$shipping_type_name for (". $totalweight ." lbs) ";
            $description = $description_default;

            // Default description case state
            $discount_shipping = false;
            $free_shipping = false;
            $free_shipping_cancel = false;

            if ( $debug == 'Enabled' )
            {
                echo( 'Discount Shipping Check: '. "$shippingtype == 0 and $subtotalprice >= $settingFreeShippingPrice and $totalweight and $settingFreeShippingDiscount and $cost" .'<hr />' );
            }

            // Calculate Shipping Description State
            if ( $settingFreeShipping and $shippingtype == 0 and $containerWeightDiscount and $subtotalprice >= $settingFreeShippingPrice )
            {
                $discount_shipping = true;
            }
            // Added for the specific product match check
            elseif ( $settingFreeShipping and $shippingtype == 0 and $freeshippingproduct )
            {
                $discount_product_shipping = true;
            }
            elseif ( $settingFreeShipping and $shippingtype == 0 and $containerWeightDiscount and $cost == $settingFreeShippingDiscount )
            {
                $free_shipping_cancel = true;
            }

            /*
            elseif ( $settingFreeShipping and $shippingtype == 0 and $containerWeightDiscount and $subtotalprice >= $settingFreeShippingPrice )
            {
                $free_shipping = true;
            }
            */

            // Setting to control discounted shipping
            if ( $discount_shipping )
            {
                if( $debug == 'Enabled' )
                    echo("Discount Terms: $cost > $settingFreeShippingDiscount and $cost > $numeric_value_00 <hr />");

                if ( $cost >= $settingFreeShippingDiscount and $cost > $numeric_value_00 )
                {
                    $cost = $cost - $settingFreeShippingDiscount;
                }

                if( $debug == 'Enabled' )
                    echo("Discount Actual: $cost ");

                // $description = $discription ."Discounted Shipping! For (".$totalweight." lbs) - $settingFreeShippingWeightDiscount lbs Discount";
                $description_discounted_shipping = "Discounted Shipping! $shipping_type_name for ".$totalweight." lbs.";
                $description = $description_discounted_shipping;
            }
            
            // If Product shipping is discounted for specific product
            // If 2 or more times the specific product match product is in cart we subtract the shipping costs
            elseif ( $discount_product_shipping )
            {
                if( $debug == 'Enabled' )
                    echo("Discount Terms: $cost > $settingFreeShippingDiscount and $cost > $numeric_value_00 <hr />");

                if ( $cost >= $settingFreeShippingDiscount and $cost > $numeric_value_00 )
                {
                    $cost = $cost - $settingFreeShippingDiscount;
                }

                if( $debug == 'Enabled' )
                    echo("Discount Actual: $cost ");

                // $description = $discription ."Discounted Shipping! For (".$totalweight." lbs) - $settingFreeShippingWeightDiscount lbs Discount";
                // $description_discounted_shipping = "Discounted Shipping for $product_Name! $shipping_type_name for ".$totalweight." lbs.";
	        $description_discounted_shipping = "Discounted Shipping for Specific Product! $shipping_type_name for ".$totalweight." lbs.";
                $description = $description_discounted_shipping;
            }
            elseif ( $free_shipping_cancel )
            {
                // Assign Shipping Cost of $00.00
                $cost = $numeric_value_00;

                $description_free_shipping = "Free Shipping! $shipping_type_name for (".$totalweight." lbs).";
                $description = $discription . $description_free_shipping;
                $askvendor = false;
            }

            /*
            elseif ( $free_shipping )
            {
                // Assign Shipping Cost of $00.00
                // $cost = $numeric_value_00;

                $description_free_shipping = "Free Shipping! $shipping_type_name for (".$totalweight." lbs)";
                $description = $discription . $description_free_shipping;
                $askvendor = false;
            }
            */

            // Defer shipping calculation description
            if ( $askvendor )
            {
                $description_deffered = " <b>$shipping_vendor_name will call you to calculate the shipping price!</b>";
                $description = $description . $description_deffered;
            }

            // Remove any existing order shipping item before appendeding a new item
            $r = new eZOrderItem;
            $r = $r->fetchList( $orderID, false );

            if( count($r) != 0 ){
                $r_id = $r[0]['id'];
                $roi = new eZOrderItem;
                $roi->removeItem( $r_id );

                if ( $debug == 'Enabled' ){
                  print_r( $r_id );
                  include_once( 'extension/ezdbug/autoloads/ezdbug.php' );
                  $d = new eZDBugOperators();
                  $d->ezdbugDump( $r, 99, true );
                  echo('<hr />');
                  // die();
                }
	    }
            // Build order item object
            $orderItem = new eZOrderItem( array( 'order_id' => $orderID,
                                                 'description' => $description,
                                                 'price' => $cost,
                                                 'vat_is_included' => true,
                                                 'vat_type_id' => 1 )
                                          );
            // Store order
            $orderItem->store();
        }

        return EZ_WORKFLOW_TYPE_STATUS_ACCEPTED;
    }
}

eZWorkflowEventType::registerType( EZ_WORKFLOW_TYPE_BCSTATICSHIPPING_ID, "bcstaticshippingtype" );

?>