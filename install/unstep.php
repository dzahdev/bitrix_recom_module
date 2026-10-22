<?php

use Bitrix\Main\Localization\Loc;

if (!check_bitrix_sessid())
{
	return;
}

Loc::loadMessages(__FILE__);

CAdminMessage::ShowNote(Loc::getMessage('DZAHDEV_RECOM_UNINSTALL_DONE'));
