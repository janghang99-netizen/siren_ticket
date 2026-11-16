<?php

namespace App\Services\SeatMap;

use RuntimeException;

/** 좌석표 이미지 읽기 실패 — 메시지는 운영사 화면에 그대로 보여 준다 (키·원문 응답은 넣지 않는다) */
class SeatMapReadFailed extends RuntimeException {}
