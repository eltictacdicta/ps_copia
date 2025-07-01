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
var t=function(){var t,n,e=!1,i=new Promise((function(i,r){t=function(){for(var t=[],n=0;n<arguments.length;n++)t[n]=arguments[n];e=!0,i.apply(void 0,t)},n=function(){for(var t=[],n=0;n<arguments.length;n++)t[n]=arguments[n];e=!0,r.apply(void 0,t)}}));return{resolve:t,reject:n,promise:i,isSettled:function(){return e}}},n=function(){function t(t){var n;this.callbacks={},this.warned=!1,this.maxListeners=null!==(n=null==t?void 0:t.maxListeners)&&void 0!==n?n:10}return t.prototype.warnIfPossibleMemoryLeak=function(t){this.warned||this.maxListeners&&this.callbacks[t].length>this.maxListeners&&(console.warn("Event Emitter: Possible memory leak detected; ".concat(String(t)," has exceeded ").concat(this.maxListeners," listeners.")),this.warned=!0)},t.prototype.on=function(t,n){return this.callbacks[t]?(this.callbacks[t].push(n),this.warnIfPossibleMemoryLeak(t)):this.callbacks[t]=[n],this},t.prototype.once=function(t,n){var e=this,i=function(){for(var r=[],s=0;s<arguments.length;s++)r[s]=arguments[s];e.off(t,i),n.apply(e,r)};return this.on(t,i),this},t.prototype.off=function(t,n){var e,i=(null!==(e=this.callbacks[t])&&void 0!==e?e:[]).filter((function(t){return t!==n}));return this.callbacks[t]=i,this},t.prototype.emit=function(t){for(var n,e=this,i=[],r=1;r<arguments.length;r++)i[r-1]=arguments[r];return(null!==(n=this.callbacks[t])&&void 0!==n?n:[]).forEach((function(t){t.apply(e,i)})),this},t}();export{n as E,t as c};
