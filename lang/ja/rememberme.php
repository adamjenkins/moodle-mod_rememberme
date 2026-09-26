<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Japanese language strings for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addbands'] = 'バンドをさらに{no}個追加する';
$string['addsuspensions'] = '休止期間をさらに{no}個追加する';
$string['anybank'] = 'このコース内のすべての問題バンク';
$string['audiocue'] = 'フィードバック時に効果音を再生する';
$string['audiocue_help'] = '解答が採点されたときに短い音を再生します。授業中や公共交通機関の中で利用するときのために、学習者は自分でこの音をオフにできます。';
$string['audiocueoff'] = 'サウンドはオフです。フィードバック音をオンにする';
$string['audiocueon'] = 'サウンドはオンです。フィードバック音をオフにする';
$string['backstopdays'] = 'バックストップ（日数）';
$string['backstopdays_help'] = '学習者が1つのバンドにとどまることのできる最長期間です。この期間を過ぎると、進捗にかかわらず次のバンドが解放されます。バックストップがない場合、学習につまずいている学習者はコース全体を通して最初のバンドにとどまり、シラバスの大半を目にしないままになる可能性があります。これは、カバレッジを最も必要としている学習者にとって最悪の結果です。バックストップにより、習熟モードは厳格な関門ではなくペース配分の目安になります。誰かの学習を早めることはありませんが、際限のない停滞を防ぎます。';
$string['backstopwarning'] = 'バックストップにより進行';
$string['band'] = 'バンド';
$string['bandcategories'] = 'このバンドのカテゴリ';
$string['bandcategories_help'] = '1つのバンドで複数のカテゴリを使用できます。新しい問題は学習者の現在のバンドとそれより下のすべてのバンドから出題されるため、以前に終わらなかったカテゴリが取り残されることはありません。';
$string['bandgradelabel'] = '現在の評定：{$a}%';
$string['bandprogress'] = 'バンドの進捗';
$string['bandreason'] = '到達理由';
$string['bandreason_backstop'] = 'バックストップ — しきい値に達しなかった';
$string['bandreason_exhausted'] = 'バンド内のすべての問題に取り組んだ';
$string['bandreason_mastery'] = 'しきい値に達した';
$string['bandreason_none'] = '開始バンド';
$string['bandreason_suspensionlimit'] = '休止期間中のため保留';
$string['bandreason_time'] = '時間経過';
$string['bandsince'] = 'このバンドの開始日時';
$string['bandsintro'] = '問題カテゴリを、教えたい順序で割り当ててください。新しい問題は学習者の現在のバンドからのみ出題されます。復習はバンドによって制限されることはありません。一度取り組んだ問題は、どのバンドの問題であっても、記憶の強さのみに基づいて再び出題されます。';
$string['bandunlocked'] = '新しい問題セットが解放されました。';
$string['breakweek'] = '休止週：学習は必須ではなく、学習すると猶予クレジットを獲得できます';
$string['cachedef_instancesettings'] = 'セッション作成時に使用される活動設定';
$string['checkanswer'] = '確認';
$string['choosecategory'] = '問題カテゴリを選択してください...';
$string['completiondetail:weeks'] = '{$a}週を満点で獲得する';
$string['completionweeks'] = '学習者が満点で獲得する必要がある週数：';
$string['completionweeksgroup'] = '満点で獲得した週';
$string['configuredefaults'] = '新しい活動のデフォルト設定';
$string['correct'] = '正解';
$string['coursestart'] = '学期開始日';
$string['coursestart_help'] = '評定は学期開始日から学期終了日まで、この時点から数えた週単位で行われます。週の区切りは、登録した時期にかかわらず、コース内のすべての学習者で共通です。これは、時間ベースモードでのバンドの解放が各学習者自身の最初のセッションから数えられるのとは意図的に異なります。解放は個人のペースを調整するものであり、評定は共通のカレンダーに従うものだからです。';
$string['day'] = '日';
$string['difficulty'] = '難易度';
$string['difficultyflagged'] = 'ほぼすべての学習者にとって難しい問題は、概念的に難しいというより、問題文の表現が不適切であることがほとんどです。これらの問題は読み直す価値があります。';
$string['duetoday'] = '本日復習予定';
$string['erroralreadyanswered'] = 'その問題にはすでに解答済みです。';
$string['errorduplicateband'] = '各バンドには異なる問題カテゴリを使用する必要があります。';
$string['errorgradingbands'] = 'バンドの定着で評定するには、「次のバンドの解放方法」を「現在のバンドが定着したとき」に設定する必要があります。';
$string['errorincompleteresponse'] = '確認する前に問題に解答してください。';
$string['errormaxchoices'] = 'すべての選択肢を表示する場合は0を、それ以外の場合は3以上を入力してください。';
$string['errornobands'] = '少なくとも1つの問題カテゴリを選択してください。';
$string['errornonnegative'] = 'この値に負の数は指定できません。';
$string['errornoquestions'] = 'この活動に割り当てられた問題カテゴリには、使用可能な問題が含まれていません。';
$string['errornotgraded'] = 'この解答は採点できなかったため、何も記録されていません。もう一度お試しください。';
$string['errorpositive'] = 'この値は0より大きくなければなりません。';
$string['errorproportionrange'] = '割合は0より大きく1未満でなければなりません。割合を1にすると、いつまでも難しいままの少数の問題のために、学習者が1つのバンドに無期限にとどまってしまう可能性があります。';
$string['errorretentionrange'] = '目標保持率は0より大きく1未満でなければなりません。';
$string['errorsessiongone'] = 'このセッションは利用できなくなりました。ページを再読み込みして、新しいセッションを開始してください。';
$string['errorstudydays'] = '学習日は1から7の間で選択してください。';
$string['errortermbackwards'] = '学期終了日は学期開始日より後でなければなりません。';
$string['errortermtoolong'] = '学期の長さは最大{$a}週です。';
$string['errorthresholdrange'] = 'しきい値は0から1の間でなければなりません。';
$string['errorwindowbackwards'] = '休止期間の終了は開始より後でなければなりません。';
$string['errorwindowoutsideterm'] = '休止期間は学期内に収まっていなければなりません。';
$string['establishedat'] = '{$a}日で定着';
$string['eventbandunlocked'] = 'バンド解放';
$string['eventquestionanswered'] = '問題解答';
$string['firstsession'] = '最初のセッション';
$string['flagreview'] = '見直しを推奨';
$string['forecast'] = '今後の復習量';
$string['forecast_help'] = '今後の各日に復習予定となる問題の数です（学習者全体の合計）。';
$string['forecastcaption'] = '今後2週間に復習予定となる問題（グループ全体）。';
$string['gracebalance'] = '猶予クレジット';
$string['gracebalance_help'] = 'うまくいかなかった週に備える保険で、コース全体に対して最初に付与され、週単位で測られます。猶予クレジットは、学習者が達成した分と満点の1週との差を埋めるもので、消費量はその差の大きさとちょうど同じです。完全に欠けた週を救済するには1.0を消費し、0.9と評価された週を補うには0.1しか消費しません。したがって、残高1.0で欠けた週を1つ救済するか、わずかに届かなかった週をいくつか補うことができます。猶予クレジットはコース終了時に、差の小さいものから順に割り当てられるため、学習者がいずれにせよ自力で吸収できたはずの週に早い段階で無駄に使われることはありません。';
$string['graceearned'] = '獲得した猶予クレジット';
$string['graceearnrate'] = '休止中のセッションごとに獲得する猶予クレジット';
$string['graceearnrate_help'] = '休止中に自主的に学習すると、猶予クレジットの残高が補充されます。これにより、遅れを取った学習者は、学習義務のない時期に遅れを取り戻すことができます。学習が休止期間内に行われた場合、またはその大部分が休止中であるため評定対象とならない週のいずれかの日に行われた場合に、休止中の学習として扱われます。学習日と同様に、各日にきちんと解答した異なる問題の数で測定されるため、同じ問題を繰り返しても何も獲得できません。獲得できる猶予クレジットは当初の付与量が上限となるため、休止期間を利用して、学期中に欠席した分を買い戻すことはできません。これは報酬ではなく挽回のための仕組みです。猶予クレジットは不足分を埋めるだけなので、不足のない学習者がこれによって得るものはありません。';
$string['graceremaining'] = '残りの猶予クレジット：{$a}';
$string['graceused'] = '使用した猶予クレジット';
$string['gradingended'] = '評定対象の週は終了しました';
$string['gradingexplained'] = '評定は、継続して戻ってくることで決まります。毎週{$a->days}日、異なる日に学習してください。{$a->items}問の異なる問題に解答するか、その日に出題されたすべての問題に取り組むと、その日が1日としてカウントされます。学期の各週が評定の同じ割合を占めるため、評定は週ごとに積み上がっていきます。';
$string['gradingexplainedbands'] = '評定は、各バンドの問題を定着させることで決まります。問題は{$a->floor}日間記憶していられるようになると定着したとみなされ、バンド内の問題の{$a->percent}%が定着するとそのバンドは満点になります。各バンドは全問題に占める割合だけの重みを持ち、一度獲得した評定が取り消されることはありません。';
$string['gradingintro'] = '評定では、学習者が正解した数ではなく、定期的に戻ってきて学習しているかどうかを測定します。各週は、学習者が学習した異なる日の数を、以下で設定する学習日数と比較して評価され、学期中の評定対象の各週が評定の同じ割合を占めます。そのため評定は学期を通じて積み上がります。学期の初めは誰の評定も低く、毎週その日数だけ学習した学習者は学期末に100%に達します。正答率で評定すると、推測を避けたり答えを調べたりすることが有利になるため、スケジューラが依存する信号が損なわれてしまいます。';
$string['gradingmethod'] = '評定方法';
$string['gradingmethod_bands'] = 'バンドの定着';
$string['gradingmethod_help'] = '評定で何を測定するかを選択します。

* **毎週の学習日**：学期の各週が評定の同じ割合を占め、学習者が学習した異なる日の数で評価されます。
* **バンドの定着**：各バンドは全問題に占める問題数の割合だけの重みを持ち、定着の度合いに応じてその分の評定を獲得します。バンド内で安定度の下限に達した問題の割合が「定着の割合」に届くと、そのバンドは満点になります。これは次のバンドを解放するのと同じ基準です。一度獲得したバンドの評定は取り消されないため、定着した後に忘れても評定は下がりません。「次のバンドの解放方法」を「現在のバンドが定着したとき」に設定する必要があります。';
$string['gradingmethod_studydays'] = '毎週の学習日';
$string['gradingnotstarted'] = '評定対象の週は{$a}に始まります';
$string['gradingsettings'] = '週ごとの完了と評定';
$string['includesubcategories'] = 'サブカテゴリを含む';
$string['incorrect'] = '不正解';
$string['itemsdue'] = '現在復習予定';
$string['itemsestablished'] = '定着';
$string['itemsseen'] = '出題済み';
$string['lapses'] = '忘却';
$string['learner'] = '学習者';
$string['learners'] = '学習者';
$string['loading'] = '次の問題を読み込んでいます...';
$string['masteryproportion'] = '定着の割合';
$string['masteryproportion_help'] = '次のバンドが解放されるまでに、安定度の下限に達していなければならないバンド内の問題の割合です。学習者が一度も見たことのない問題はこの割合に不利に数えられるため、バンドの大部分に実際に取り組むまでは条件を満たしません。この値は1より十分に小さくしてください。100%にすると、いつまでも難しいままの少数の問題のために、学習者が1つのバンドに永遠にとどまってしまいます。';
$string['maxchoices'] = '多肢選択問題あたりの選択肢数';
$string['maxchoices_help'] = '多肢選択問題で提示する選択肢の最大数です（正解を含みます）。これより多くの選択肢を持つ問題では、正解と、ランダムに選ばれた不正解の選択肢が表示されます。不正解の選択肢は問題が出題されるたびに選び直されるため、学習者は答えの見た目を覚えることができません。選択肢数が上限より少ない問題には影響しません。すべての選択肢を提示するには0を入力してください。';
$string['meandifficulty'] = '平均難易度';
$string['meanlapses'] = '平均忘却回数';
$string['meanstability'] = '平均安定度（日）';
$string['modulename'] = 'Remember Me';
$string['modulename_help'] = 'Remember Me は問題バンクの問題を間隔反復でスケジュールし、学習者がそれぞれの問題を忘れかけたちょうどそのときに復習できるようにします。

自己評価式のフラッシュカードツールとは異なり、スケジュールは Moodle の既存の問題採点機能によって判定された、解答が実際に正しかったかどうかだけに基づいて決まります。そのため自己評価の偏りがなくなり、実際の問題タイプでそのまま利用できます。

学習者は正答率ではなく、毎週いくつもの異なる日に学習しに戻ってくることで評定されます。また、各学習者は自分のペースで問題カテゴリを進んでいきます。';
$string['modulename_link'] = 'mod/rememberme/view';
$string['modulenameplural'] = 'Remember Me 活動';
$string['newperday'] = '1日あたりの新しい問題数';
$string['newperday_help'] = '学習者が1日に取り組める新しい問題の上限数です。この上限がないと、意欲的な学習者が数百もの新しい問題を最初に詰め込み、1週間後には復習に埋もれてしまいます。';
$string['nextquestion'] = '次の問題';
$string['noactivity'] = 'まだ学習記録がありません';
$string['noattemptdesc'] = 'あなたはこの活動を閲覧できますが、ここで学習することはできないため、問題は表示されません。';
$string['nobanks'] = 'このコースには、使用可能な問題を含む問題バンクが見つかりませんでした。まず問題バンク活動を追加し、問題を作成してください。';
$string['noinstances'] = 'このコースには Remember Me 活動がありません。';
$string['nothingdue'] = '現在、復習予定の問題はありません';
$string['nothingduedesc'] = '今のところ用意されている問題はすべて終わりました。明日また学習しに来てください。学習した日はすべて今週の分として数えられます。';
$string['nothingdueplaindesc'] = '今のところ用意されている問題はすべて終わりました。明日また学習しに来てください。';
$string['nothingoffereddesc'] = '現在、ここで学習できるものはありません。この状態が変わらない場合は、教師に知らせてください。';
$string['notquite'] = '惜しい';
$string['notstarted'] = '未開始';
$string['ontimegrace'] = '期限内の解答で得られる猶予クレジット';
$string['ontimegrace_help'] = '復習予定の問題がたまる前に戻ってくる学習者に報いる設定です。問題が復習予定になった時期の近くに解答する学習者は最大でこの量の猶予クレジットを得られますが、2週間に1度まとめて取り組む学習者は、問題が期限超過のまま放置されていたため、まったく得られません。これは点数ではなく猶予クレジットとして与えられるため、悪かった週を補うことにしか使えず、誰かを満点より上に引き上げることはありません。学習者が得るものは、ここで得る分も休止期間中に学習して得る分も、すべて最初に付与される猶予クレジットが上限となるため、この値だけを引き上げても取り戻せる量は増えません。0に設定すると無効になります。';
$string['overduenow'] = '期限超過';
$string['passthreshold'] = '部分点のしきい値';
$string['passthreshold_help'] = '部分点がこの割合以上であれば想起できたものとみなし、間隔を延ばします。これを下回る場合、その問題は忘れたものとして扱われます。';
$string['pausecorrect'] = '正解後のフィードバック表示時間（ms）';
$string['pausecorrect_help'] = '次の問題が表示されるまでに結果を表示する時間です。';
$string['pauseincorrect'] = '不正解後のフィードバック表示時間（ms）';
$string['pauseincorrect_help'] = '正解後の表示時間より長くします。理解すべき内容が多く、学習者が正しい解答を読む時間が必要なためです。';
$string['pluginadministration'] = 'Remember Me 管理';
$string['pluginname'] = 'Remember Me';
$string['poolsettings'] = '問題プール';
$string['poolsize'] = 'プール内の問題数';
$string['privacy:metadata:rememberme_bandstate'] = '各学習者が順序付けられた問題カテゴリをどこまで進んだかです。';
$string['privacy:metadata:rememberme_bandstate:bandlevel'] = '学習者が到達したカテゴリです。';
$string['privacy:metadata:rememberme_bandstate:bestprogress'] = '各バンドがこれまでに到達した最大の定着度です。バンドの定着による評定で保持されます。';
$string['privacy:metadata:rememberme_bandstate:firstsession'] = '学習者が最初に学習した日時です。';
$string['privacy:metadata:rememberme_bandstate:userid'] = 'この進捗が属する学習者です。';
$string['privacy:metadata:rememberme_review_log'] = '評定されたすべての解答の永続的な記録です。スケジューリングモデルを検証・改善できるように保存されます。';
$string['privacy:metadata:rememberme_review_log:fraction'] = '問題のうち正しく解答された割合です。';
$string['privacy:metadata:rememberme_review_log:latency'] = '学習者が解答にかかった時間（ミリ秒）です。';
$string['privacy:metadata:rememberme_review_log:questionbankentryid'] = '解答された問題です。';
$string['privacy:metadata:rememberme_review_log:rating'] = 'スケジューリングモデルが解答をどのように解釈したかです。';
$string['privacy:metadata:rememberme_review_log:timecreated'] = '問題が解答された日時です。';
$string['privacy:metadata:rememberme_review_log:userid'] = '解答した学習者です。';
$string['privacy:metadata:rememberme_schedule'] = '各学習者における各問題の現在の記憶の強さです。次にいつ出題するかを決めるために使用されます。';
$string['privacy:metadata:rememberme_schedule:difficulty'] = 'この学習者にとってのこの問題の難易度です。';
$string['privacy:metadata:rememberme_schedule:duedate'] = 'この問題の次の復習予定日時です。';
$string['privacy:metadata:rememberme_schedule:lapses'] = '学習者がこの問題を忘れた回数です。';
$string['privacy:metadata:rememberme_schedule:questionbankentryid'] = 'この状態が属する問題です。';
$string['privacy:metadata:rememberme_schedule:reps'] = '学習者がこの問題を想起できた回数です。';
$string['privacy:metadata:rememberme_schedule:stability'] = '学習者が現在この問題をどの程度よく覚えているかです。';
$string['privacy:metadata:rememberme_schedule:userid'] = 'この状態が属する学習者です。';
$string['privacy:metadata:rememberme_session'] = '各学習セッションで出題された問題です。';
$string['privacy:metadata:rememberme_session:timecreated'] = 'セッションが開始された日時です。';
$string['privacy:metadata:rememberme_session:userid'] = '学習した学習者です。';
$string['privacy:metadata:rememberme_slot'] = '学習セッション内で学習者に出題された個々の問題です。';
$string['privacy:metadata:rememberme_slot:isnew'] = 'この問題が学習者にとって新しい問題だったかどうかです。';
$string['privacy:metadata:rememberme_slot:questionbankentryid'] = '出題された問題です。';
$string['privacy:metadata:rememberme_slot:sessionid'] = 'この問題が出題された学習セッションです。';
$string['privacy:metadata:rememberme_slot:timeshown'] = '問題が学習者に表示された日時です。';
$string['privacy:metadata:rememberme_slot:timeshownms'] = '問題が学習者に表示された日時（ミリ秒単位）で、解答時間の計測に使用されます。';
$string['privacy:metadata:rememberme_weeks'] = 'スケジュールに対する週ごとの進捗で、評定に使用されます。';
$string['privacy:metadata:rememberme_weeks:clearedmask'] = 'その週のうち、学習者が出題されたすべての問題に取り組んだ日です。';
$string['privacy:metadata:rememberme_weeks:completed'] = '学習日による評定の導入前：学習者がその週に完了した問題の数です。';
$string['privacy:metadata:rememberme_weeks:daysrequired'] = 'その週の開始時点で、1週間を満たすために必要だった学習日の数です。';
$string['privacy:metadata:rememberme_weeks:daysstudied'] = 'その週のうち、学習者が1日として数えられるだけ十分に学習した日の数です。';
$string['privacy:metadata:rememberme_weeks:fraction'] = 'その週の学習日のうち、達成された学習日の割合です。';
$string['privacy:metadata:rememberme_weeks:legacy'] = 'その週が、学習日による評定の導入前に使われていたルールで採点されたかどうかです。';
$string['privacy:metadata:rememberme_weeks:snapshottarget'] = '学習日による評定の導入前：その週に学習者に設定された問題の数です。';
$string['privacy:metadata:rememberme_weeks:userid'] = 'この進捗が属する学習者です。';
$string['privacy:metadata:rememberme_weeks:weekno'] = 'これがコースの第何週にあたるかです。';
$string['progressthisweek'] = '今週の学習日：{$a->target}日中{$a->done}日';
$string['progresstoday'] = '今日：{$a->target}問中{$a->done}問';
$string['progresstodaydone'] = '今日は学習日として数えられます';
$string['question'] = '問題';
$string['questionbank'] = '問題バンク';
$string['questionbank_help'] = '以下のカテゴリをどの問題バンクから出題するかを指定します。このコースから利用できるすべての問題バンクから選択する場合は、「このコース内のすべての問題バンク」のままにしてください。';
$string['questiongone'] = '問題はバンクに存在しなくなりました';
$string['questionsanswered'] = '解答済みの問題';
$string['recalculategrades'] = '評定を再計算する';
$string['recalculategradesconfirm'] = 'すべての学習者の週を解答から再採点し、今すぐ評定表を更新しますか？ 現在の設定が使用されます。学習日による評定の導入前に記録された週は保護されたままで、削除されるものはありません。';
$string['recalculategradesdone'] = '評定を再計算しました：{$a->learners}人の学習者について{$a->weeks}週を再採点し、評定表を更新しました。';
$string['relativeload'] = '2週間の負荷に占める割合';
$string['rememberme:addinstance'] = '新しい Remember Me 活動を追加する';
$string['rememberme:attempt'] = '問題に解答する';
$string['rememberme:recalculategrades'] = 'Remember Me の評定を再計算する';
$string['rememberme:view'] = 'Remember Me 活動を表示する';
$string['rememberme:viewreports'] = 'Remember Me のレポートを表示する';
$string['reportbands'] = 'バンドの進行状況';
$string['reportbandscaption'] = '各学習者が順序付けられた問題カテゴリをどこまで進んだかを示します。バックストップにより進行済みとされた学習者は、しきい値を満たしていませんでした。シラバス全体に触れられるように先へ進められたものであり、対応を検討すべきシグナルです。';
$string['reportcoverage'] = 'カバレッジと記憶保持率';
$string['reportcoveragecaption'] = '各学習者が問題プールのどれだけを見たか、そしてそのうちどれだけを現在よく理解しているかを示します。';
$string['reportdifficulty'] = '問題の難易度';
$string['reportdifficulty_help'] = 'ほぼすべての学習者にとって難易度が高い問題は、概念が難しいというよりも、問題自体に欠陥があることが多いです。';
$string['reportdifficultycaption'] = 'グループ全体を通じて、各問題がどれだけ難しいと判明しているかを示します。';
$string['reportnodata'] = 'まだレポートする内容がありません。学習者が解答を始めると表示されます。';
$string['reports'] = 'レポート';
$string['reportweeks'] = '週ごとの完了状況';
$string['reportweekscaption'] = '各学習者の週ごとの状況です。その週に必要な日数に対して実際に学習できた学習日数と、適用された猶予クレジットを示します。';
$string['reportweeksintro'] = '週の評定は、正答率ではなく、学習者が学習した異なる日の数で決まります。学習者が1セッション分の異なる問題に解答するか、その日に出題されたすべての問題を終えると、その日が学習日として数えられます。';
$string['resetall'] = 'すべてのスケジュール、復習履歴、週ごとの進捗を削除する';
$string['retention'] = '記憶保持率';
$string['reviews'] = '復習';
$string['schedulingsettings'] = 'スケジューリングモデル';
$string['sessioncomplete'] = 'セッション完了';
$string['sessioncompletedesc'] = '現在復習予定の問題はすべて終わりました。よくできました。';
$string['sessionprogress'] = 'このセッションの進捗';
$string['sessionsettings'] = 'セッションとフィードバック';
$string['sessionsize'] = '1セッションあたりの問題数';
$string['sessionsize_help'] = '1回のセッションで出題される問題の最大数です。';
$string['showanswer'] = '正答';
$string['stability'] = '安定度';
$string['stabilityfloor'] = '安定度の下限（日）';
$string['stabilityfloor_help'] = '問題が定着とみなされるまでに、どの程度よく覚えている必要があるかを指定します。デフォルトの14日では、1つの問題におよそ3～4回の復習の成功が必要で、間隔そのものがそれらの復習を隔てるため、少なくとも2週間にわたることになります。つまり、習熟モードは詰め込み学習で急いで進めることができません。それがこのモードの目的です。';
$string['startsession'] = '学習を開始する';
$string['streak'] = '連続記録';
$string['streaklabel'] = '週連続';
$string['streakweeks'] = '{$a}週連続';
$string['studyagain'] = 'もう一度学習する';
$string['studydays'] = '週あたりの学習日数';
$string['studydays_help'] = 'その週を満点として数えるために、学習者が1週間のうち何日の異なる日に学習する必要があるかを指定します。週の評定は、学習した日数をこの数で割った値（上限100%）です。たとえば3に設定した場合、2日学習した学習者のその週の評定は67%になります。

学習者がセッションの問題数と同じ数の異なる問題に解答するか、その日に出題されたすべての問題を終えるか、いずれか早い方を満たすと、その日が学習日として数えられます。2つ目の条件により、復習予定の問題が少ない学習者が、出題数が少ないことを理由に減点されることはなく、減点されるのは学習に戻ってこなかった場合だけです。1日の区切りは、学期が始まる時刻を基準とします。

休止期間や学期終了によって短くなった週では、必要な日数がその割合に応じて少なくなります（端数は切り上げ）。大部分が休止期間にあたる週では、学習日は必要ありません。この数を変更すると、翌週から適用されます。すでに始まっている週は、開始時の数がそのまま使われます。';
$string['suspensionend'] = '終了';
$string['suspensionintro'] = '学期中の休止期間です。休止期間中はスケジューリングの時計が止まるため、復習予定になる問題はなく、学習者が休み明けに大量の期限超過の復習に直面することはありません。大部分が休止期間にあたる週は評定されません。その週に学習しなかった学習者が不利になることはなく、その週に学習すると猶予クレジットを獲得できます。一部が休止期間にあたる週では、必要な学習日数が開講日数の割合に応じて少なくなります（端数は切り上げ）。';
$string['suspensionname'] = '名称';
$string['suspensionsettings'] = '休止期間';
$string['suspensionstart'] = '開始';
$string['targetretention'] = '目標保持率';
$string['targetretention_help'] = '問題が再び出題された時点で、学習者がその問題を覚えている可能性をどの程度にするかを指定します。値を低くすると復習の回数は減りますが、忘れることが増えます。これは教育上の判断であるため、設定は教師に委ねられています。通常は0.9から始めるのが一般的です。';
$string['taskmaintenance'] = 'Remember Me メンテナンス';
$string['taskrefreshgrades'] = 'Remember Me の評定を更新する';
$string['termdatesupdated'] = '学期の日付と休止期間がコース開始日に合わせて移動されました';
$string['termend'] = '学期終了日';
$string['termend_help'] = '評定はここで終了します。学期が週の途中で終わる場合、その最後の週に必要な学習日数は、学期内に含まれる日数の割合に応じて少なくなります（端数は切り上げ、最低1日）。';
$string['unlockinterval'] = 'バンド間の日数';
$string['unlockinterval_help'] = '時間ベースモードで、学習者が各バンドに取り組む期間です。この期間はコースの開始日からではなく、学習者自身の最初のセッションから数えられます。そのため、第3週に参加した学習者に一度に4つのバンドが解放されることはありません。';
$string['unlockmode'] = '次のバンドの解放方法';
$string['unlockmode_exhausted'] = 'バンド内のすべての問題が出題されたとき';
$string['unlockmode_help'] = '時間ベースモードでは、一定の間隔ごとに1つのバンドが解放されます。予測しやすくシラバスに沿って進められますが、学習者が内容を定着させたかどうかは考慮されません。

習熟モードでは、学習者が現在のバンドの大部分を実際に定着させるまで待ちます。間隔そのものが経過するのに時間がかかるため、急いで進めることはできません。順調に学習している学習者にとっては、2つのモードの進み方はほぼ同じです。違いが出るのは苦戦している学習者の場合であり、そこでこの選択が重要になります。';
$string['unlockmode_mastery'] = '現在のバンドが定着したとき';
$string['unlockmode_time'] = '一定期間の経過後';
$string['uselatency'] = '解答時間を使用する';
$string['uselatency_help'] = '有効にすると、通常より速く解答した場合はより強い記憶とみなされ、間隔が少し長くなります。解答時間は、その問題タイプにおける学習者自身の通常の速さと比較されます。また、解答時間によって正解が不正解に変わることはありません。無効にすると、正誤のみが使用されます。';
$string['viewreports'] = 'レポートを表示する';
$string['weekno'] = '第{$a}週';
$string['weeksuspended'] = '休止中';
