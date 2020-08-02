<div class="kb-content">
    <div class="kb-content-header">
        <h1>{l s='My Orders' mod='kbmarketplace'}</h1>
        <div class="clearfix"></div>
    </div>
    <div class="outer-border kb-tcenter">
        <ul class="summary-list-group">
            <li class="summary-box blue-summary">
                <div class="summary-single-box">
                    <div class="mo_kpi_content big">
                            <i class="big icon-money"></i>
                            <span class="big-title">{l s='Total Sale' mod='kbmarketplace'}</span>
                            <span class="big-value">{$total_revenue|escape:'htmlall':'UTF-8'}</span>
                    </div>
                </div>
                <div class="clearfix"></div>
            </li>
            <li class="summary-box yellow-summary">
                <div class="summary-single-box">
                    <div class="mo_kpi_content big">
                            <i class="big icon-tint"></i>
                            <span class="big-title">{l s='Total Products Sold' mod='kbmarketplace'}</span>
                            <span class="big-value">{$total_sold_products|intval}</span>
                    </div>
                </div>
                <div class="clearfix"></div>
            </li>
            <li class="summary-box red-summary">
                <div class="summary-single-box">
                    <div class="mo_kpi_content big">
                            <i class="big icon-book"></i>
                            <span class="big-title">{l s='Pending Orders' mod='kbmarketplace'}</span>
                            <span class="big-value">{$total_pending_orders|intval}</span>
                    </div>
                </div>
                <div class="clearfix"></div>
            </li>
        </ul>
    </div>
    <div class='kb-vspacer5'></div>
    {if isset($kbfilter)}
        {$kbfilter}{*Variable contains css and html content, escape not required*}
    {/if}
    <div class="kbalert kbalert-warning">
        <i class="icon-warning-sign"></i>
        {l s='When using filters, the item searched by the reference number will not work if # character is present in the search. Search by using the text shown before the # character.' mod='kbmarketplace'}
        <br/>
        {l s='Example-: For reference id VCATACPTS#2, search for VCATACPTS only.' mod='kbmarketplace'}
    </div>
    {if isset($kblist)}
        <div class="kb-vspacer5"></div>
        {$kblist}{*Variable contains css and html content, escape not required*}
    {/if}
</div>
{*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer tohttp://www.prestashop.com for more information.
* We offer the best and most useful modules PrestaShop and modifications for your online store.
*
* @category  PrestaShop Module
* @author    knowband.com <support@knowband.com>
* @copyright 2016 knowband
* @license   see file: LICENSE.txt
*}
