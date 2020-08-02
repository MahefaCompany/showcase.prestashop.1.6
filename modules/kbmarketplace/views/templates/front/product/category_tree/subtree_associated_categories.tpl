{if isset($nodes)}
	{$nodes} {*Variable contains css and html content, escape not required*}
{/if}

<script type="text/javascript">
{if isset($selected_categories) && !empty($selected_categories)}
	{assign var=imploded_selected_categories value='","'|implode:$selected_categories}
	var selected_categories = new Array("{$imploded_selected_categories}"); //Variable contains css and html content, escape not required

	$('#{$id_tree|escape:'htmlall':'UTF-8'}').tree('collapseAll');
	$('#{$id_tree|escape:'htmlall':'UTF-8'}').find(":input").each(
		function()
		{
			if ($.inArray($(this).val(), selected_categories) != -1)
			{
				$(this).prop("checked", true);
				$(this).parent().addClass("tree-selected");
				$(this).parents("ul.tree").each(
					function()
					{
						$(this).children().children().children(".icon-folder-close")
							.removeClass("icon-folder-close")
							.addClass("icon-folder-open");
						$(this).show();
					}
				);
			}
		}
	);
{/if}
</script>
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