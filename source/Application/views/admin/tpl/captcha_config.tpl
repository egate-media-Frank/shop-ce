[{include file="headitem.tpl" title="O3_CAPTCHA_ADMIN_NAV_LABEL"|oxmultilangassign}]

[{* CAPTCHA provider — admin configuration form. *}]

<form name="myedit" id="myedit" action="[{$oViewConf->getSelfLink()}]" method="post">
    [{$oViewConf->getHiddenSid()}]
    <input type="hidden" name="cl" value="captcha_config">
    <input type="hidden" name="fnc" value="save">

    <fieldset>
        <legend>[{oxmultilang ident="O3_CAPTCHA_ADMIN_NAV_LABEL"}]</legend>

        <p>
            <label for="sCaptchaProvider">
                [{oxmultilang ident="O3_CAPTCHA_PROVIDER_LABEL"}]
            </label>
            <select id="sCaptchaProvider" name="sCaptchaProvider">
                <option value="">[{oxmultilang ident="O3_CAPTCHA_PROVIDER_NONE"}]</option>
                [{foreach from=$oView->getCaptchaProviders() key="provId" item="titleIdent"}]
                    <option value="[{$provId}]" [{if $provId == $oView->getActiveProviderId()}]selected="selected"[{/if}]>[{oxmultilang ident=$titleIdent}]</option>
                [{/foreach}]
            </select>
        </p>

        [{foreach from=$oView->getActiveProviderConfigFields() item="field"}]
            [{assign var="ftype" value=$field->getType()}]
            <p>
                <label for="providerField_[{$field->getKey()}]">
                    [{oxmultilang ident=$field->getLabelIdent()}]
                </label>
                [{if $ftype == "password"}]
                    <input type="password"
                           id="providerField_[{$field->getKey()}]"
                           name="providerField_[{$field->getKey()}]"
                           value="[{$oView->getProviderSettingValue($field->getKey())|escape:'html'}]">
                [{elseif $ftype == "number"}]
                    <input type="number"
                           step="0.1" min="0" max="1"
                           id="providerField_[{$field->getKey()}]"
                           name="providerField_[{$field->getKey()}]"
                           value="[{$oView->getProviderSettingValue($field->getKey())|escape:'html'}]">
                [{else}]
                    <input type="text"
                           id="providerField_[{$field->getKey()}]"
                           name="providerField_[{$field->getKey()}]"
                           value="[{$oView->getProviderSettingValue($field->getKey())|escape:'html'}]">
                [{/if}]
            </p>
        [{/foreach}]

        <p>
            <label>
                <input type="checkbox" name="blCaptchaRequireConsent" value="1"
                       [{if $oView->isConsentRequired()}]checked="checked"[{/if}]>
                [{oxmultilang ident="O3_CAPTCHA_REQUIRE_CONSENT"}]
            </label>
        </p>

        <p>
            <strong>[{oxmultilang ident="O3_CAPTCHA_FORMS_LABEL"}]</strong>
        </p>

        [{foreach from=$oView->getCaptchaFormIds() item="formId"}]
            <p>
                <label>
                    <input type="checkbox" name="blCaptchaForm_[{$formId}]" value="1"
                           [{if $oView->isFormEnabled($formId)}]checked="checked"[{/if}]>
                    [{oxmultilang ident="O3_CAPTCHA_FORM_"|cat:$formId}]
                </label>
            </p>
        [{/foreach}]

        <p>
            <input type="submit" value="[{oxmultilang ident="GENERAL_SAVE"}]" class="edittext">
        </p>
    </fieldset>
</form>

[{include file="bottomnaviitem.tpl"}]
[{include file="bottomitem.tpl"}]
