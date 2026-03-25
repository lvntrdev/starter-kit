// resources/js/formbuilder/index.ts

export type {
    FormBuilderConfig,
    FieldConfig,
    FormLayout,
    SelectOption,
    SelectFieldConfig,
    TitleFieldConfig,
    SlotFieldConfig,
    InputTextFieldConfig,
    InputNumberFieldConfig,
    InputOtpFieldConfig,
    InputMaskFieldConfig,
    DatePickerFieldConfig,
    PasswordFieldConfig,
    TextareaFieldConfig,
    ToggleButtonFieldConfig,
    CheckboxFieldConfig,
    ToggleSwitchFieldConfig,
    FileUploadFieldConfig,
    ColorSelectorFieldConfig,
    ExistingMedia,
    OptionFilter,
    FormResourceConfig,
} from './types';

export {
    FormBuilder,
    InputTextBuilder,
    InputNumberBuilder,
    InputOtpBuilder,
    InputMaskBuilder,
    DatePickerBuilder,
    SelectFieldBuilder,
    CheckboxBuilder,
    PasswordBuilder,
    TextareaBuilder,
    ToggleButtonBuilder,
    ToggleSwitchBuilder,
    FileUploadBuilder,
    ColorSelectorBuilder,
    TitleBuilder,
    SlotBuilder,
} from './builder';

import {
    FormBuilder,
    InputTextBuilder,
    InputNumberBuilder,
    InputOtpBuilder,
    InputMaskBuilder,
    DatePickerBuilder,
    SelectFieldBuilder,
    CheckboxBuilder,
    PasswordBuilder,
    TextareaBuilder,
    ToggleButtonBuilder,
    ToggleSwitchBuilder,
    FileUploadBuilder,
    ColorSelectorBuilder,
    TitleBuilder,
    SlotBuilder,
} from './builder';

/**
 * FormBuilder — fluent API for configuring the <FormBuilder> component.
 *
 * @example
 * const config = FB.form()
 *   .layout('horizontal')
 *   .cols(2)
 *   .addFields(
 *     FB.inputText().key('name').label('Full Name').required(),
 *     FB.inputText().key('email').label('Email').inputType('email'),
 *     FB.select().key('province').label('Province').optionsUrl('/api/provinces'),
 *     FB.select().key('district').label('District')
 *       .optionsUrl(values => values.province ? `/api/districts?province_id=${values.province}` : null)
 *       .visible(values => !!values.province),
 *     FB.password().key('password').label('Password').toggleMask(),
 *     FB.toggleSwitch().key('is_active').label('Active'),
 *   )
 *   .build();
 */
export const FB = {
    form: () => new FormBuilder(),
    inputText: () => new InputTextBuilder(),
    inputNumber: () => new InputNumberBuilder(),
    inputOtp: () => new InputOtpBuilder(),
    inputMask: () => new InputMaskBuilder(),
    datePicker: () => new DatePickerBuilder(),
    select: () => new SelectFieldBuilder('select'),
    multiselect: () => new SelectFieldBuilder('multiselect'),
    radio: () => new SelectFieldBuilder('radio'),
    selectButton: () => new SelectFieldBuilder('select-button'),
    checkbox: () => new CheckboxBuilder(),
    checkboxGroup: () => new SelectFieldBuilder('checkbox-group'),
    password: () => new PasswordBuilder(),
    textarea: () => new TextareaBuilder(),
    toggleButton: () => new ToggleButtonBuilder(),
    toggleSwitch: () => new ToggleSwitchBuilder(),
    fileUpload: () => new FileUploadBuilder(),
    colorSelector: () => new ColorSelectorBuilder(),
    title: (text?: string) => new TitleBuilder(text),
    slot: () => new SlotBuilder(),
};
