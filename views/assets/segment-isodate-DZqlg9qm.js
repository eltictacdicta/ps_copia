/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
var e,t={};function r(){if(e)return t;e=1;var r=/^(\d{4})(?:-?(\d{2})(?:-?(\d{2}))?)?(?:([ T])(\d{2}):?(\d{2})(?::?(\d{2})(?:[,\.](\d{1,}))?)?(?:(Z)|([+\-])(\d{2})(?::?(\d{2}))?)?)?$/;return t.parse=function(e){var t=[1,5,6,7,11,12],n=r.exec(e),a=0;if(!n)return new Date(e);for(var d,s=0;d=t[s];s++)n[d]=parseInt(n[d],10)||0;n[2]=parseInt(n[2],10)||1,n[3]=parseInt(n[3],10)||1,n[2]--,n[8]=n[8]?(n[8]+"00").substring(0,3):0," "===n[4]?a=(new Date).getTimezoneOffset():"Z"!==n[9]&&n[10]&&(a=60*n[11]+n[12],"+"===n[10]&&(a=0-a));var f=Date.UTC(n[1],n[2],n[3],n[5],n[6]+a,n[7],n[8]);return new Date(f)},t.is=function(e,t){return"string"==typeof e&&((!t||!1!==/^\d{4}-\d{2}-\d{2}/.test(e))&&r.test(e))},t}export{r};
