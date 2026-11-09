<?php
declare(strict_types=1);

/**
 * Campo de formulário acessível: rótulo visível, dica, mensagem de erro e ligações ARIA
 * (for/id, aria-describedby, aria-invalid). Use em todos os formulários do SAA.
 *
 * Exemplo:
 *   <?= partial('field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email',
 *                         'autocomplete' => 'username', 'required' => true]) ?>
 *
 * @var string                    $name          atributo name (e id, quando $id não é informado)
 * @var string                    $label         rótulo visível
 * @var string|null               $id            id do controle (padrão: $name)
 * @var string|null               $type          text | email | password | tel | date | number | search | url | textarea | select
 * @var mixed                     $value         valor atual (padrão: old($name)); nunca é repetido em campos de senha
 * @var string|null               $error         mensagem de erro (padrão: field_error($name))
 * @var string|null               $hint          texto de ajuda exibido abaixo do campo
 * @var bool|null                 $required      campo obrigatório (os demais ganham "(opcional)")
 * @var string|null               $autocomplete  valor do atributo autocomplete
 * @var string|null               $placeholder   texto de exemplo (no select, vira a primeira opção vazia)
 * @var array<string,string>|null $options       valor => rótulo (somente type=select)
 * @var array<string,string>|null $attrs         atributos extras (maxlength, inputmode, min, max...); on* e style são ignorados
 * @var bool|null                 $toggle        mostra o botão "mostrar senha" (type=password; aparece só com JavaScript)
 * @var bool|null                 $autofocus     foco automático ao carregar a página
 */
$fieldName = isset($name) && is_string($name) ? $name : '';
$fieldId = isset($id) && is_string($id) && $id !== '' ? $id : $fieldName;
$fieldType = isset($type) && is_string($type) ? $type : 'text';
$fieldLabel = isset($label) && is_string($label) ? $label : $fieldName;
$fieldRequired = !empty($required);
$fieldError = isset($error) ? $error : field_error($fieldName);
$fieldError = is_string($fieldError) && $fieldError !== '' ? $fieldError : null;
$fieldHint = isset($hint) && is_string($hint) && $hint !== '' ? $hint : null;
$fieldValue = $fieldType === 'password' ? '' : (isset($value) ? $value : old($fieldName, ''));
$fieldValue = is_scalar($fieldValue) ? (string)$fieldValue : '';
$fieldOptions = isset($options) && is_array($options) ? $options : [];
$fieldPlaceholder = isset($placeholder) && is_string($placeholder) && $placeholder !== '' ? $placeholder : null;
$fieldToggle = $fieldType === 'password' && !empty($toggle);

$fieldHintId = $fieldHint !== null ? $fieldId . '-hint' : null;
$fieldErrorId = $fieldError !== null ? $fieldId . '-error' : null;
$fieldDescribedBy = trim(($fieldHintId ?? '') . ' ' . ($fieldErrorId ?? ''));

$fieldAttrs = '';
if (isset($attrs) && is_array($attrs)) {
    foreach ($attrs as $attrName => $attrValue) {
        $attrName = strtolower((string)$attrName);
        if (preg_match('/^[a-z][a-z0-9-]*$/', $attrName) !== 1 || str_starts_with($attrName, 'on') || $attrName === 'style') {
            continue;
        }
        $fieldAttrs .= ' ' . $attrName . '="' . e($attrValue) . '"';
    }
}

$fieldCommon = ' id="' . e($fieldId) . '" name="' . e($fieldName) . '"'
    . ($fieldRequired ? ' required' : '')
    . (isset($autocomplete) && is_string($autocomplete) && $autocomplete !== '' ? ' autocomplete="' . e($autocomplete) . '"' : '')
    . (!empty($autofocus) ? ' autofocus' : '')
    . ($fieldError !== null ? ' aria-invalid="true"' : '')
    . ($fieldDescribedBy !== '' ? ' aria-describedby="' . e($fieldDescribedBy) . '"' : '')
    . $fieldAttrs;
?>
<div class="field">
  <label class="field__label" for="<?= e($fieldId) ?>"><?= e($fieldLabel) ?><?php if (!$fieldRequired): ?> <span class="field__optional">(opcional)</span><?php endif; ?></label>
  <div class="field__control">
    <?php if ($fieldType === 'textarea'): ?>
      <textarea class="textarea"<?= $fieldCommon ?><?= $fieldPlaceholder !== null ? ' placeholder="' . e($fieldPlaceholder) . '"' : '' ?>><?= e($fieldValue) ?></textarea>
    <?php elseif ($fieldType === 'select'): ?>
      <select class="select"<?= $fieldCommon ?>>
        <?php if ($fieldPlaceholder !== null): ?><option value=""><?= e($fieldPlaceholder) ?></option><?php endif; ?>
        <?php foreach ($fieldOptions as $optionValue => $optionLabel): ?>
          <option value="<?= e((string)$optionValue) ?>"<?= (string)$optionValue === $fieldValue ? ' selected' : '' ?>><?= e($optionLabel) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input class="input<?= $fieldToggle ? ' input--with-action' : '' ?>" type="<?= e($fieldType) ?>"<?= $fieldCommon ?><?= $fieldValue !== '' ? ' value="' . e($fieldValue) . '"' : '' ?><?= $fieldPlaceholder !== null ? ' placeholder="' . e($fieldPlaceholder) . '"' : '' ?>>
      <?php if ($fieldToggle): ?>
        <button type="button" class="field__toggle" data-password-toggle aria-controls="<?= e($fieldId) ?>" aria-pressed="false" hidden>
          <?= partial('icon', ['name' => 'eye', 'class' => 'icon--on']) ?>
          <?= partial('icon', ['name' => 'eye-off', 'class' => 'icon--off']) ?>
          <span class="sr-only">Mostrar senha</span>
        </button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($fieldHint !== null): ?>
    <p class="field__hint" id="<?= e((string)$fieldHintId) ?>"><?= e($fieldHint) ?></p>
  <?php endif; ?>
  <?php if ($fieldError !== null): ?>
    <p class="field__error" id="<?= e((string)$fieldErrorId) ?>">
      <?= partial('icon', ['name' => 'alert', 'class' => 'icon--sm']) ?>
      <span><span class="sr-only">Erro: </span><?= e($fieldError) ?></span>
    </p>
  <?php endif; ?>
</div>
