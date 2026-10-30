@extends('layouts.app')

@section('css')
@vite('resources/css/user/attendance-register.css')
@endsection

@section('content')
<div class="attendance__content">
    <h1 class="attendance__heading">勤怠登録</h1>
    <div class="attendance__status">
        <p class="attendance__status--item">{{ $statusLabel }}</p>
    </div>
    <form class="attendance__form" action="{{ route('attendance.clockIn') }}" method="post">
        @csrf
        <div class="current-date">
            <input class="current-date__item" type="text" value="{{ $formattedDate }}" readonly>
        </div>
        <div class="current-time">
            <input class="current-time__item" type="text" id="currentTime" value="{{ $formattedTime }}" readonly>
        </div>
        <div class="attendance__button">
            <button class="attendance__button--submit--clock-in" type="submit" formaction="{{ route('attendance.clockIn') }}" @disabled(! $canClockIn)>出勤</button>
            <button class="attendance__button--submit--clock-out" type="submit" formaction="{{ route('attendance.clockOut') }}" @disabled(! $canClockOut)>退勤</button>
            <button class="attendance__button--submit--break-in" type="submit" formaction="{{ route('attendance.restStart') }}" @disabled(! $canRestStart)>休憩入</button>
            <button class="attendance__button--submit--break-out" type="submit" formaction="{{ route('attendance.restEnd') }}" @disabled(! $canRestEnd)>休憩戻</button>
            @if ($status === \App\Models\Attendance::STATUS_CLOCKED_OUT)
                <p class="attendance__message">お疲れ様でした。</p>
            @endif
        </div>
    </form>
</div>

<script>
    function updateTime() {
        const now = new Date();
        const options = { hour: '2-digit', minute: '2-digit', hour12: false };
        document.getElementById('currentTime').value = now.toLocaleTimeString('ja-JP', options);
    }
    setInterval(updateTime, 1000);
    updateTime();
</script>
@endsection
