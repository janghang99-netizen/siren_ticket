// SPDX-License-Identifier: MIT
pragma solidity 0.8.24;

/**
 * SIREN 공식 좌석 티켓 — 양도 불가(소울바운드) ERC-721 + ERC-5192.
 *  - 운영사(operator) 지갑만 발행·소각·입장 표시를 한다. 팬은 운영사 보관 지갑 주소로 받는다.
 *  - 전송·승인 함수는 모두 막혀 있다: 리셀 불가, 거래되지 않는 디지털 티켓.
 *  - 체인에는 토큰 ID·보유 주소·메타데이터 해시만 올라간다. 이름·전화번호 같은 개인정보는 올리지 않는다.
 * 외부 라이브러리 없이 한 파일로 둔다(검토 범위를 좁히기 위해).
 */
contract SirenTicket {
    string public constant name = "SIREN Ticket";
    string public constant symbol = "SRNT";

    address public operator;
    string private _baseURI;

    mapping(uint256 => address) private _owners;
    mapping(address => uint256) private _balances;
    mapping(uint256 => bytes32) public metadataHash;
    mapping(uint256 => bool) public used;

    event Transfer(address indexed from, address indexed to, uint256 indexed tokenId);
    event Locked(uint256 tokenId);
    event Used(uint256 indexed tokenId);
    event OperatorChanged(address indexed previousOperator, address indexed newOperator);
    event BaseURIChanged(string baseURI);

    error NotOperator();
    error Soulbound();
    error NoSuchToken();
    error AlreadyMinted();
    error AlreadyUsed();
    error ZeroAddress();

    modifier onlyOperator() {
        if (msg.sender != operator) revert NotOperator();
        _;
    }

    constructor(string memory baseURI_) {
        operator = msg.sender;
        _baseURI = baseURI_;
        emit OperatorChanged(address(0), msg.sender);
    }

    // ── ERC-165 ──
    function supportsInterface(bytes4 interfaceId) external pure returns (bool) {
        return interfaceId == 0x01ffc9a7   // ERC-165
            || interfaceId == 0x80ac58cd   // ERC-721
            || interfaceId == 0x5b5e139f   // ERC-721 Metadata
            || interfaceId == 0xb45a3c0e;  // ERC-5192 (잠긴 토큰)
    }

    // ── 조회 ──
    function balanceOf(address owner) external view returns (uint256) {
        if (owner == address(0)) revert ZeroAddress();
        return _balances[owner];
    }

    function ownerOf(uint256 tokenId) public view returns (address owner) {
        owner = _owners[tokenId];
        if (owner == address(0)) revert NoSuchToken();
    }

    function tokenURI(uint256 tokenId) external view returns (string memory) {
        ownerOf(tokenId);
        return string.concat(_baseURI, _toString(tokenId));
    }

    /// ERC-5192: 모든 토큰은 잠겨 있다
    function locked(uint256 tokenId) external view returns (bool) {
        ownerOf(tokenId);
        return true;
    }

    // ── 운영사 ──
    function mint(address to, uint256 tokenId, bytes32 hash) external onlyOperator {
        if (to == address(0)) revert ZeroAddress();
        if (_owners[tokenId] != address(0)) revert AlreadyMinted();
        _owners[tokenId] = to;
        unchecked { _balances[to] += 1; }
        metadataHash[tokenId] = hash;
        emit Transfer(address(0), to, tokenId);
        emit Locked(tokenId);
    }

    /// 예매 취소 → 소각
    function burn(uint256 tokenId) external onlyOperator {
        address owner = ownerOf(tokenId);
        delete _owners[tokenId];
        unchecked { _balances[owner] -= 1; }
        emit Transfer(owner, address(0), tokenId);
    }

    /// 입장 검표 → 사용 표시 (한 번만)
    function markUsed(uint256 tokenId) external onlyOperator {
        ownerOf(tokenId);
        if (used[tokenId]) revert AlreadyUsed();
        used[tokenId] = true;
        emit Used(tokenId);
    }

    function setBaseURI(string calldata baseURI_) external onlyOperator {
        _baseURI = baseURI_;
        emit BaseURIChanged(baseURI_);
    }

    /// 운영사 키 교체 (KMS 로 옮길 때)
    function setOperator(address newOperator) external onlyOperator {
        if (newOperator == address(0)) revert ZeroAddress();
        emit OperatorChanged(operator, newOperator);
        operator = newOperator;
    }

    // ── 전송·승인 막기 (양도 불가) ──
    function transferFrom(address, address, uint256) external pure { revert Soulbound(); }
    function safeTransferFrom(address, address, uint256) external pure { revert Soulbound(); }
    function safeTransferFrom(address, address, uint256, bytes calldata) external pure { revert Soulbound(); }
    function approve(address, uint256) external pure { revert Soulbound(); }
    function setApprovalForAll(address, bool) external pure { revert Soulbound(); }

    function getApproved(uint256 tokenId) external view returns (address) {
        ownerOf(tokenId);
        return address(0);
    }

    function isApprovedForAll(address, address) external pure returns (bool) {
        return false;
    }

    function _toString(uint256 value) private pure returns (string memory) {
        if (value == 0) return "0";
        uint256 digits;
        for (uint256 v = value; v != 0; v /= 10) digits++;
        bytes memory buf = new bytes(digits);
        while (value != 0) {
            digits--;
            buf[digits] = bytes1(uint8(48 + (value % 10)));
            value /= 10;
        }
        return string(buf);
    }
}
