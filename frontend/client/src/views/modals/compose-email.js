/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2015 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 ************************************************************************/

Espo.define('Views.Modals.ComposeEmail', 'Views.Modals.Edit', function (Dep) {

    return Dep.extend({

        scope: 'Email',

        layoutName: 'composeSmall',

        saveButton: false,

        fullFormButton: false,

        editViewName: 'Email.Record.Compose',

        columnCount: 2,

        setup: function () {
            Dep.prototype.setup.call(this);

            this.buttonList.unshift({
                name: 'saveDraft',
                text: this.translate('Save Draft'),
            });

            this.buttonList.unshift({
                name: 'send',
                text: this.getLanguage().translate('Send'),
                style: 'primary'
            });

            this.header = this.getLanguage().translate('Compose Email');
        },

        actionSend: function (dialog) {
            var editView = this.getView('edit');

            var model = editView.model;

            var afterSend = function () {
                this.trigger('after:save', model);
                dialog.close();
            };

            editView.once('after:send', afterSend, this);

            var $send = dialog.$el.find('button[data-name="send"]');
            $send.addClass('disabled');
            var $saveDraft = dialog.$el.find('button[data-name="saveDraft"]');
            $saveDraft.addClass('disabled');
            editView.once('cancel:save', function () {
                $send.removeClass('disabled');
                $saveDraft.removeClass('disabled');
                editView.off('after:save', afterSend);
            }, this);

            editView.send();
        },

        actionSaveDraft: function (dialog) {
            var editView = this.getView('edit');

            var model = editView.model;

            var $send = dialog.$el.find('button[data-name="send"]');
            $send.addClass('disabled');
            var $saveDraft = dialog.$el.find('button[data-name="saveDraft"]');
            $saveDraft.addClass('disabled');

            var afterSave = function () {
                $saveDraft.removeClass('disabled');
                $send.removeClass('disabled');
                Espo.Ui.success(this.translate('savedAsDraft', 'messages', 'Email'));
            }.bind(this);

            editView.once('after:save', afterSave , this);

            editView.once('cancel:save', function () {
                $send.removeClass('disabled');
                $saveDraft.removeClass('disabled');
                editView.off('after:save', afterSave);
            }, this);

            editView.saveDraft();
        }

    });
});

