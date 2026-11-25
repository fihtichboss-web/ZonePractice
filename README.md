# ZonePractice

PvP-практика для Minecraft Bedrock (PocketMine-MP, API 5): дуэли 1v1/2v2, FFA, настраиваемый кнокбэк.

## Как скачать плагин
1. Открой вкладку **Actions** в репозитории.
2. Выбери последний запуск **Build phar** (зелёная галочка).
3. Внизу в разделе **Artifacts** скачай `ZonePractice` (это zip, внутри `ZonePractice.phar`).

## Как установить
1. Скачай PocketMine-MP 5 (файл `PocketMine-MP.phar`) с pmmp.io.
2. Запусти сервер один раз, чтобы появились папки, потом останови.
3. Положи `ZonePractice.phar` в папку `plugins/`.
4. Запусти сервер снова.

## Настройка (в игре, от имени админа/OP)
- `/zp setlobby` — лобби, `/zp setffa` — спавн FFA
- `/zp setarena <имя> 1` и `/zp setarena <имя> 2` — точки арены
- Кнокбэк: `plugins/ZonePractice/config.yml`, раздел `knockback`.

## Игра
`/duel 1v1`, `/duel 2v2`, `/ffa`, `/leave`

## Как открыть сервер для друзей (playit.gg)
Bedrock работает по UDP, а GitHub Codespaces UDP не пробрасывает, поэтому нужен туннель:
1. Зарегистрируйся на playit.gg и скачай агент.
2. Создай туннель типа **Minecraft Bedrock** на локальный порт 19132.
3. Дай друзьям адрес и порт из playit.gg.
