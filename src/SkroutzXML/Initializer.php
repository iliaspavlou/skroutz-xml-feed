<?php

namespace Pan\SkroutzXML;

use Pan\MenuPages\Fields\Button;
use Pan\MenuPages\Fields\Nonce;
use Pan\MenuPages\Fields\Range;
use Pan\MenuPages\Fields\Raw;
use Pan\MenuPages\Fields\Select;
use Pan\MenuPages\Fields\Select2;
use Pan\MenuPages\Fields\SwitchField;
use Pan\MenuPages\Fields\Text;
use Pan\MenuPages\PageElements\Components\CmpFields;
use Pan\MenuPages\PageElements\Components\CmpForm;
use Pan\MenuPages\PageElements\Components\CmpTab;
use Pan\MenuPages\PageElements\Components\CmpTabForm;
use Pan\MenuPages\PageElements\Containers\CnrCollapsible;
use Pan\MenuPages\PageElements\Containers\CnrPanelComponents;
use Pan\MenuPages\PageElements\Containers\CnrTabbedSettings;
use Pan\MenuPages\PageElements\Containers\CnrTabs;
use Pan\MenuPages\Pages\Page;
use Pan\MenuPages\Pages\SubPage;
use Pan\MenuPages\WpMenuPages;
use Pan\SkroutzXML\Logs\Logger;
use Respect\Validation\Validator;

class Initializer {
    protected $pluginFile;
    /**
     * @var Options
     */
    protected $options;

    public function __construct( $pluginFile ) {
        $this->pluginFile = $pluginFile;

        $this->options = Options::getInstance();

        add_action( 'init', [ $this, 'checkRequest' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'actionAdminEnqueueScripts' ], false, true );
        add_action( 'wp_ajax_skz_generate_now', [ new Ajax(), 'generateNow' ] );

        add_action( 'wp_loaded', array( $this, 'setupOptionsPage' ) );

        register_activation_hook( $this->pluginFile, [ $this, 'activation' ] );
        register_uninstall_hook( $this->pluginFile, [ '\\Pan\\SkroutzXML\\Initializer', 'uninstall' ] );
    }

    public function actionAdminEnqueueScripts() {
        wp_enqueue_script(
            'skz_gen_now_js',
            plugins_url( 'assets/js/generate-now.min.js', $this->pluginFile ), [ 'jquery' ],
            false,
            true
        );
    }

    public function checkRequest() {
        $generateVar    = $this->options->get( 'xml_generate_var' );
        $generateVarVal = $this->options->get( 'xml_generate_var_value' );

        if ( isset( $_REQUEST[$generateVar] ) && $_REQUEST[$generateVar] === $generateVarVal ) {
            add_action( 'wp_loaded', [ new Skroutz(), 'generateAndPrint' ], PHP_INT_MAX );
        }
    }

    public function activation() {
        $xmlInterval = $this->options->get( 'xml_interval' );

        if ( ! is_numeric( $xmlInterval ) ) {
            switch ( $xmlInterval ) {
                case 'every30m':
                case 'hourly':
                    $intervalInHours = 1;
                    break;
                case 'twicedaily':
                    $intervalInHours = 12;
                    break;
                case 'daily':
                default:
                    $intervalInHours = 24;
                    break;
            }
            $this->options->set( 'xml_interval', $intervalInHours );
        }

        return true;
    }

    public static function uninstall() {
        delete_option( Options::OPTIONS_NAME );
        delete_option( Logger::LOG_NAME );

        return true;
    }

    public function setupOptionsPage() {
        if ( ! is_admin() ) {
            return;
        }
        $wpMenuPages = new WpMenuPages( $this->pluginFile, $this->options );

        $menuPage = new SubPage( $wpMenuPages, SubPage::PARENT_SETTINGS, 'Skroutz XML Settings' );

        $availOptions = [ ];
        foreach ( Options::$availOptions as $value => $label ) {
            $availOptions[ $label ] = (string) $value;
        }

        $availOptionsDoNotInclude                   = $availOptions;
        $availOptionsDoNotInclude['Do not Include'] = count( $availOptions );

        $attrTaxonomies = [ ];
        foreach ( wc_get_attribute_taxonomies() as $atrTax ) {
            $attrTaxonomies[ $atrTax->attribute_label ] = $atrTax->attribute_id;
        }

        $tabs = new CnrTabbedSettings( $menuPage, Page::POSITION_MAIN );

        $colInfo     = new CnrCollapsible( $menuPage, Page::POSITION_ASIDE, 'XML File Information' );
        $panelGenNow = new CnrCollapsible( $menuPage, Page::POSITION_ASIDE, 'Generate Now' );

        $tabGeneral  = new CmpTabForm( $tabs, 'General Settings', true );
        $tabAdvanced = new CmpTabForm( $tabs, 'Advanced Settings' );
        $tabMap      = new CmpTabForm( $tabs, 'Map Fields Settings' );
        $tabLog      = new CmpTab( $tabs, 'Log' );

        $xmlLocationFld = new Text( $tabGeneral, 'xml_location' );
        $xmlLocationFld->setLabel( 'Cached XML File Location' )
                       ->attachValidator( Validator::stringType() );

        $xmlFileNameFld = new Text( $tabGeneral, 'xml_fileName' );
        $xmlFileNameFld->setLabel( 'XML Filename' )
                       ->attachValidator( Validator::stringType()->notEmpty() );

        $xmlIntervalFld = new Range( $tabGeneral, 'xml_interval' );
        $xmlIntervalFld->setLabel( 'XML File Generation Interval' )
                       ->setMin( 1 )
                       ->setMax( 24 );

        $xmlGenVarFld = new Text( $tabAdvanced, 'xml_generate_var' );
        $xmlGenVarFld->setLabel( 'XML Generation Request Variable' )
                     ->attachValidator( Validator::stringType()->length( 1 )->alnum() );

        $xmlGenVarValFld = new Text( $tabAdvanced, 'xml_generate_var_value' );
        $xmlGenVarValFld->setLabel( 'XML Generation Request Variable Value' )
                        ->attachValidator( Validator::stringType()->length( 8 )->alnum() );

        // TODO Are we gonna use this?
//        $productsIncFld = new PostType( $tabGeneral, 'products_include' );
//        $productsIncFld->setLabel( '' )
//                       ->setMultiple( true )
//                       ->validate( Validator::arrayType() );

        $availInStockFld = new Select( $tabGeneral, 'avail_inStock' );
        $availInStockFld->setLabel( 'Product availability when item is in stock' )
                        ->setOptions( $availOptions )
                        ->attachValidator( Validator::numeric()
                                                    ->min( min( $availOptions ) )
                                                    ->max( max( $availOptions ) ) );

        $availOutOfStockFld = new Select( $tabGeneral, 'avail_outOfStock' );
        $availOutOfStockFld->setLabel( 'Product availability when item is out of stock' )
                           ->setOptions( $availOptionsDoNotInclude )
                           ->attachValidator( Validator::numeric()
                                                       ->min( min( $availOptionsDoNotInclude ) )
                                                       ->max( max( $availOptionsDoNotInclude ) ) );

        $availBackOrdersFld = new Select( $tabGeneral, 'avail_backorders' );
        $availBackOrdersFld->setLabel( 'Product availability when item is out of stock and backorders are allowed' )
                           ->setOptions( $availOptionsDoNotInclude )
                           ->attachValidator( Validator::numeric()
                                                       ->min( min( $availOptionsDoNotInclude ) )
                                                       ->max( max( $availOptionsDoNotInclude ) ) );

        $mapIdFld = new SwitchField( $tabMap, 'map_id' );
        $mapIdFld->setLabel( 'Product ID' )
                 ->setOptions( [ 'Use Product SKU' => 0, 'Use Product ID' => 1 ] )
                 ->attachValidator( Validator::numeric()->min( 0 )->max( 1 ) );


        $options = array_merge(
            [ 'Product Categories' => 'product_cat', 'Product Tags' => 'product_tag' ],
            $attrTaxonomies
        );

        $mapProductCatFld = new Select2( $tabMap, 'map_manufacturer' );
        $mapProductCatFld->setLabel( 'Product Manufacturer Field' )
                         ->setOptions( $options )
                         ->attachValidator( Validator::in( $options ) );

        $options = array_merge(
            [ 'Use Product SKU' => 0 ],
            $attrTaxonomies
        );

        $mapMpnFld = new Select2( $tabMap, 'map_mpn' );
        $mapMpnFld->setLabel( 'Product Manufacturer SKU' )
                  ->setOptions( $options )
                  ->attachValidator( Validator::in( $options ) );

        $options = array_merge(
            [ 'Use Product Name' => 0 ],
            $attrTaxonomies
        );

        $mapMpnName = new Select2( $tabMap, 'map_name' );
        $mapMpnName->setLabel( 'Product Name' )
                   ->setOptions( $options )
                   ->attachValidator( Validator::in( $options ) );

        $mapAppendSkuFld = new SwitchField( $tabMap, 'map_name_append_sku' );
        $mapAppendSkuFld->setLabel( 'Append SKU to Product Name' )
                        ->attachValidator( Validator::numeric()->between( 0, 1 ) );

        $options = [
            'Thumbnail' => 'thumbnail',
            'Medium'    => 'medium',
            'Large'     => 'large',
            'Full'      => 'full',
        ];

        $mapProdImgFld = new Select2( $tabMap, 'map_image' );
        $mapProdImgFld->setLabel( 'Product Image' )
                      ->setOptions( $options )
                      ->validate( Validator::in( $options ) );

        $options = [
            'Regular Price'     => 0,
            'Sales Price'       => 1,
            'Price Without Tax' => 2,
        ];

        $mapPriceFld = new Select2( $tabMap, 'map_price_with_vat' );
        $mapPriceFld->setLabel( 'Product Price' )
                    ->setOptions( $options )
                    ->attachValidator( Validator::in( $options ) );

        $options = array_merge(
            [ 'Product Categories' => 'product_cat', 'Product Tags' => 'product_tag' ],
            $attrTaxonomies
        );

        $mapCatFld = new Select2( $tabMap, 'map_category' );
        $mapCatFld->setLabel( 'Product Categories' )
                  ->setOptions( $options )
                  ->attachValidator( Validator::in( $options ) );

        $mapAppendSkuFld = new SwitchField( $tabMap, 'map_category_tree' );
        $mapAppendSkuFld->setLabel( 'Include full path to product category' )
                        ->attachValidator( Validator::numeric()->between( 0, 1 ) );

        $mapAppendSkuFld = new SwitchField( $tabMap, 'is_fashion_store' );
        $mapAppendSkuFld->setLabel( 'This Store Contains Fashion Products' )
                        ->attachValidator( Validator::numeric()->between( 0, 1 ) );

        $mapSizeFld = new Select2( $tabMap, 'map_size' );
        $mapSizeFld->setLabel( 'Product Sizes' )
                   ->setOptions( $attrTaxonomies )
                   ->attachValidator( Validator::in( $attrTaxonomies ) );

        $mapSizeFld = new Select2( $tabMap, 'map_color' );
        $mapSizeFld->setLabel( 'Product Colors' )
                   ->setOptions( $attrTaxonomies )
                   ->attachValidator( Validator::in( $attrTaxonomies ) );

        $mapAppendSkuFld = new SwitchField( $tabMap, 'is_book_store' );
        $mapAppendSkuFld->setLabel( 'This is a Bookstore' )
                        ->attachValidator( Validator::numeric()->between( 0, 1 ) );

        $options = array_merge(
            [ 'Use Product SKU' => 0 ],
            $attrTaxonomies
        );

        $mapSizeFld = new Select2( $tabMap, 'map_isbn' );
        $mapSizeFld->setLabel( 'ISBN' )
                   ->setOptions( $attrTaxonomies )
                   ->attachValidator( Validator::in( $options ) );

        $tabLog->setContent( Logger::getLogMarkUp() );

        $cmpGenNow = new CmpFields( $panelGenNow );
        $genNowBtn = new Button( $cmpGenNow, 'Generate XML Now' );
        $genNowBtn->setClass( 'btn btn-success col-md-9 gen-now-button' );
        new Nonce($cmpGenNow, 'skz_generate_now', 'nonce');

        $skz      = new Skroutz();
        $fileInfo = $skz->getXmlObj()->getFileInfo();
        if ( empty( $fileInfo ) ) {
            $content = '<div class="alert alert-default">
                        File not generated yet. Please use the <i>Generate XML Now</i>
                        button to generate a new XML file</div>';
        } else {
            $content = '<ul class="list-group">';
            foreach ( $fileInfo as $item ) {
                $content .= '<li class="list-group-item">';
                $content .= $item['label'] . ': <strong>' . $item['value'] . '</strong>';
                $content .= '</li>';
            }
            $content .= '</ul>';
        }

        $raw = new Raw(new CmpFields($colInfo));
        $raw->setContent($content);

        $panelDonate = new CnrPanelComponents($menuPage, $menuPage::POSITION_ASIDE);
        $panelDonate->setTitle('Support this Plugin');

        $donateForm = new CmpForm($panelDonate);
        $donateSelect = new Select($donateForm, 'donate');
        $donateSelect->setOptions(['1' => 0, '2' => 1]);
    }
}